<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use App\Controller\CastleHouseReleaseController;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\OnlineBookingConfig;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Event\ReservationStatusChangedEvent;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Service\CastleHouseReleaseService;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ReleaseTestRooms extends AppartmentRepository
{
    public array $rooms = [];
    public function __construct() {}
    public function findForPublicBooking(array $roomIds, array $subsidiaryIds): array { return $this->rooms; }
}

class ReleaseTestReservations extends ReservationRepository
{
    public array $records = [];
    public function __construct() {}
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return array_values(array_filter($this->records, static fn (Reservation $r): bool =>
            (!isset($criteria['appartment']) || $r->getAppartment() === $criteria['appartment'])
            && (!isset($criteria['startDate']) || $r->getStartDate()->format('Y-m-d') === $criteria['startDate']->format('Y-m-d'))
            && (!isset($criteria['endDate']) || $r->getEndDate()->format('Y-m-d') === $criteria['endDate']->format('Y-m-d'))
            && (!isset($criteria['reservationOrigin']) || $r->getReservationOrigin() === $criteria['reservationOrigin'])));
    }
}

class ReleaseTestConfig extends OnlineBookingConfigService
{
    public ReservationOrigin $origin;
    public function __construct() { $this->origin = new ReservationOrigin(); }
    public function getAllowedRoomIds(?OnlineBookingConfig $config = null): array { return [1, 2]; }
    public function getAllowedSubsidiaryIds(?OnlineBookingConfig $config = null): array { return [1]; }
    public function getReservationOrigin(?OnlineBookingConfig $config = null): ?ReservationOrigin { return $this->origin; }
}

class ReleaseTestStatuses extends EntityRepository
{
    public function __construct(public ?ReservationStatus $status) {}
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object { return $this->status; }
}

class ReleaseTestManager extends EntityManager
{
    public int $flushes = 0;
    public function __construct(public ReservationStatus $status) {}
    public function getRepository(string $className): EntityRepository { return new ReleaseTestStatuses($this->status); }
    public function flush(): void { ++$this->flushes; }
    public function remove(object $object): void { throw new RuntimeException('Cancellation must not delete records.'); }
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$rooms = new ReleaseTestRooms();
foreach (['Dudley Room', 'Stanley Room'] as $i => $name) {
    $room = new Appartment(); $room->setId($i+1); $room->setDescription($name); $rooms->rooms[] = $room;
}
$config = new ReleaseTestConfig();
$records = new ReleaseTestReservations();
$held = (new ReservationStatus())->setIsBlocking(true);
$cancelled = (new ReservationStatus())->setCode(ReservationStatus::CODE_CANCELED_NOSHOW)->setIsBlocking(false);
$manager = new ReleaseTestManager($cancelled);
$events = new EventDispatcher();
$changes = 0;
$events->addListener(ReservationStatusChangedEvent::class, function () use (&$changes): void { ++$changes; });
$reflection = new ReflectionClass(ReservationService::class);
$statusService = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('em')->setValue($statusService, $manager);
$reflection->getProperty('eventDispatcher')->setValue($statusService, $events);
$service = new CastleHouseReleaseService($manager, $rooms, $records, $config, $statusService);

$guest = new Customer(); $guest->setFirstname('Test'); $guest->setLastname('Guest');
$contact = new CustomerAddresses(); $contact->setEmail('guest@example.test'); $guest->addCustomerAddress($contact);
foreach ($rooms->rooms as $i => $room) {
    $record = new Reservation(); $record->setId($i+11); $record->setAppartment($room);
    $record->setStartDate(new DateTime('2026-10-23')); $record->setEndDate(new DateTime('2026-10-24'));
    $record->setReservationOrigin($config->origin); $record->setBooker($guest);
    $record->setReservationStatus($held); $record->setRemark('[CHB:000000000001] [CHB:000000000002]');
    $records->records[] = $record;
}
$input = ['arrival'=>'2026-10-23','departure'=>'2026-10-24','room'=>'Dudley Room',
          'name'=>'Test Guest','email'=>'guest@example.test','marker'=>'[CHB:000000000001]'];
check($service->find($input) === $records->records[0], 'Wrong room matched.');

// Wrong identities or marker must never silently free a held room.
foreach ([['email'=>'other@example.test'], ['name'=>'Another Guest'], ['marker'=>'[CHB:ffffffffffff]']] as $wrong) {
    $rejected = false;
    try { $service->find(array_replace($input, $wrong)); }
    catch (ConflictHttpException) { $rejected = true; }
    check($rejected && $manager->flushes === 0, 'Mismatched booking identity was accepted.');
}

// Existing duplicates must be reviewed rather than selecting the first.
$duplicate = clone $records->records[0]; $duplicate->setId(99); $records->records[] = $duplicate;
$rejected = false;
try { $service->find($input); } catch (ConflictHttpException) { $rejected = true; }
check($rejected && $manager->flushes === 0, 'Duplicate active bookings were not rejected.');
array_pop($records->records);

$records->records[0]->setStartDate(new DateTime('2026-10-25'));
$records->records[0]->setEndDate(new DateTime('2026-10-26'));
$rejected = false;
try { $service->find($input); } catch (ConflictHttpException) { $rejected = true; }
check($rejected && $manager->flushes === 0, 'A moved booking was mistaken for a deleted booking.');
$records->records[0]->setStartDate(new DateTime('2026-10-23'));
$records->records[0]->setEndDate(new DateTime('2026-10-24'));

// Exercise the actual controller with Symfony's real session CSRF manager.
$stack = new RequestStack();
$rootRequest = Request::create('/'); $rootRequest->setSession(new Session(new MockArraySessionStorage())); $stack->push($rootRequest);
$tokens = new CsrfTokenManager(null, new SessionTokenStorage($stack));
$container = new Container(); $container->set('security.csrf.token_manager', $tokens);
$controller = new CastleHouseReleaseController(); $controller->setContainer($container);
$attributes = (new ReflectionClass($controller))->getAttributes(IsGranted::class);
check($attributes[0]->getArguments()[0] === 'ROLE_RESERVATIONS', 'Controller lost its write-role requirement.');
$token = json_decode($controller->form($tokens)->getContent(), true)['token'];
$request = Request::create('/reservation/castle-house/release', 'POST', $input+['_token'=>'wrong','reservation_id'=>'11']);
$request->attributes->set('_route', 'castle_house.release.cancel');
check($controller->release($request, $service)->getStatusCode() === 403 && $manager->flushes === 0, 'Invalid CSRF caused a mutation.');
$request->request->set('_token', $token); $request->request->set('reservation_id', '12');
check($controller->release($request, $service)->getStatusCode() === 409 && $manager->flushes === 0, 'A different reservation ID was cancelled.');
$request->request->set('reservation_id', '11');
$response = $controller->release($request, $service);
check(json_decode($response->getContent(), true)['state'] === 'free', 'Cancellation was not verified.');
check(!$records->records[0]->getReservationStatus()->isBlocking(), 'Selected room still blocks dates.');
check($records->records[1]->getReservationStatus()->isBlocking(), 'A companion room was cancelled.');
check($records->records[0]->getBooker() === $guest && count($records->records) === 2, 'Guest or reservation history was deleted.');
check($manager->flushes === 1 && $changes === 1, 'Standard status-change hooks were not used exactly once.');
$controller->release($request, $service);
check($manager->flushes === 1 && $changes === 1, 'A retry repeated the cancellation.');
check(!str_contains($response->getContent(), 'guest@example.test'), 'Response leaked guest contact data.');

echo "PASS: exact room matching, identity/marker/duplicate safeguards, CSRF, cancellation via standard status hooks, companion preservation, retained history and idempotent retries. No database, network or live bookings used.\n";
