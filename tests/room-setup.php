<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use App\Controller\CastleHouseRoomController;
use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Service\CastleHouseRoomService;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\Security\Http\Attribute\IsGranted;

function verifyRoom(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

class SetupRooms extends AppartmentRepository
{
    public function __construct(private EntityManager $em) {}
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    { return $this->em->getRepository(Appartment::class)->findBy($criteria, $orderBy, $limit, $offset); }
    public function findForPublicBooking(array $roomIds, array $subsidiaryIds): array
    { return array_values(array_filter($this->findBy([]), fn ($room) => $room->isActive()
        && in_array($room->getId(), $roomIds, true) && in_array($room->getObject()->getId(), $subsidiaryIds, true))); }
}
class SetupConfig extends OnlineBookingConfigService
{
    public function __construct(private OnlineBookingConfig $value, private ReservationOrigin $origin, private SetupRooms $rooms) {}
    public function getConfig(): OnlineBookingConfig { return $this->value; }
    public function getAllowedRoomIds(?OnlineBookingConfig $config = null): array
    { return $this->value->getRoomsMode() === OnlineBookingConfig::ROOMS_MODE_SELECTED ? $this->value->getSelectedRoomIds()
        : array_map(fn ($room) => $room->getId(), $this->rooms->findBy([])); }
    public function getAllowedSubsidiaryIds(?OnlineBookingConfig $config = null): array { return $this->value->getSelectedSubsidiaryIds(); }
    public function getReservationOrigin(?OnlineBookingConfig $config = null): ?ReservationOrigin { return $this->origin; }
}

if (!Type::hasType('uuid')) Type::addType('uuid', UuidType::class);
$orm = ORMSetup::createAttributeMetadataConfiguration(['/var/www/html/src/Entity'], true);
$orm->enableNativeLazyObjects(true);
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $orm);
$metadata = $em->getMetadataFactory()->getAllMetadata();
foreach ($metadata as $item) $item->customRepositoryClassName = null;
$tables = [Appartment::class, RoomCategory::class, Price::class, Subsidiary::class,
           App\Entity\CalendarSync::class, ReservationOrigin::class, OnlineBookingConfig::class];
(new SchemaTool($em))->createSchema(array_map(fn ($name) => $em->getClassMetadata($name), $tables));
$property = new Subsidiary(); $property->setName('Castle House'); $property->setDescription('Test property');
$origin = new ReservationOrigin(); $origin->setName('Website');
$em->persist($property); $em->persist($origin);
$roomIds = [];
foreach (['Dudley Room', 'Stanley Room', 'Gee Room'] as $i => $label) {
    $category = (new RoomCategory())->setName($label);
    $em->persist($category);
    $room = new Appartment(); $room->setNumber((string)($i+1)); $room->setDescription($label);
    $room->setBedsMax(2); $room->setObject($property); $room->setRoomCategory($category);
    $em->persist($room); $em->flush(); $roomIds[] = $room->getId();
    $price = new Price(); $price->setDescription('Existing rate'); $price->setPrice('100.00'); $price->setVat(0.0);
    $price->setType(2); $price->setActive(true); $price->setNumberOfPersons(2); $price->setMinStay(1); $price->setAllDays(true);
    $price->setAllPeriods(true); $price->setIsPerRoom(true); $price->addRoomCategory($category)->addReservationOrigin($origin);
    $category->addPrice($price); $em->persist($price);
}
$config = (new OnlineBookingConfig())->setEnabled(true)->setRoomsMode(OnlineBookingConfig::ROOMS_MODE_SELECTED)
    ->setSelectedRoomIds($roomIds)->setSubsidiariesMode(OnlineBookingConfig::SUBSIDIARIES_MODE_SELECTED)
    ->setSelectedSubsidiaryIds([$property->getId()])->setReservationOriginId($origin->getId());
$em->persist($config); $em->flush();
$repository = new SetupRooms($em); $settings = new SetupConfig($config, $origin, $repository);
$service = new CastleHouseRoomService($em, $repository, $settings);
$input = ['key'=>'fazakerley', 'label'=>'Fazakerley Room', 'description'=>'Super king room',
          'anchors'=>json_encode(['Dudley Room','Stanley Room','Gee Room']), 'nightlyPence'=>'20000', 'weekendPence'=>'25000'];
$result = $service->ensure($input);
verifyRoom($result['state'] === 'ready' && $result['roomId'] > 0, 'New room was not provisioned.');
verifyRoom($em->getRepository(Appartment::class)->count([]) === 4, 'Expected exactly one new physical room.');
verifyRoom(in_array($result['roomId'], $config->getSelectedRoomIds(), true), 'New room not released for online booking.');
$new = $em->find(Appartment::class, $result['roomId']);
verifyRoom($new->getBedsMax() === 2 && $new->getObject()->getId() === $property->getId(), 'Wrong capacity or property.');
$prices = $em->getRepository(Price::class)->findAll();
verifyRoom(count($prices) === 7, 'Expected 1-guest and 2-guest usual/weekend prices.');
foreach (array_slice($prices, 3) as $price) {
    verifyRoom($price->getIsPerRoom() && count($price->getRoomCategories()) === 1
        && $price->getRoomCategories()->first()->getId() === $new->getRoomCategory()->getId(), 'New prices affected another room or charged per guest.');
    verifyRoom((float)$price->getPrice() === ($price->getFriday() ? 250.0 : 200.0), 'Wrong weekday/weekend amount.');
}
$retry = $service->ensure($input);
verifyRoom($retry === $result && $em->getRepository(Appartment::class)->count([]) === 4
    && $em->getRepository(Price::class)->count([]) === 7, 'Retried request created duplicate rooms or rates.');
verifyRoom(count($config->getSelectedRoomIds()) === 4, 'Retry duplicated the released room ID.');
$anotherProperty=new Subsidiary(); $anotherProperty->setName('Other property'); $anotherProperty->setDescription('Other'); $em->persist($anotherProperty); $em->flush();
// HTTP access and CSRF guards must reject before creating any inventory.
$stack = new RequestStack(); $request = Request::create('/'); $request->setSession(new Session(new MockArraySessionStorage())); $stack->push($request);
$tokens = new CsrfTokenManager(null, new SessionTokenStorage($stack));
$container = new Container(); $container->set('security.csrf.token_manager', $tokens);
$controller = new CastleHouseRoomController(); $controller->setContainer($container);
$denied = $controller->ensure(Request::create('/reservation/castle-house/rooms','POST',$input),$service);
verifyRoom($denied->getStatusCode() === 403, 'Missing CSRF token was accepted.');
$readOnly = $controller->form($tokens);
verifyRoom($readOnly->getStatusCode() === 200 && str_contains($readOnly->headers->get('Cache-Control'),'no-store'), 'Unsafe setup token caching.');
$class = (new ReflectionClass($controller))->getAttributes(IsGranted::class);
verifyRoom($class[0]->getArguments()[0] === 'ROLE_RESERVATIONS', 'Room setup lost its reservation-management role guard.');
$route=(new ReflectionMethod($controller,'ensure'))->getAttributes(Route::class)[0]->getArguments();
verifyRoom($route['methods'] === ['POST'], 'Room setup must never write on GET.');
foreach (['0','-1','no-price','1000000'] as $badPrice) {
    $rejected=false;
    try { $service->ensure([...$input, 'nightlyPence'=>$badPrice]); } catch (BadRequestHttpException) { $rejected=true; }
    verifyRoom($rejected, 'Invalid price accepted.');
}
// Each rejected transaction uses a fresh manager, as a failed Doctrine transaction closes it.
$connection=$em->getConnection();
foreach ([['label'=>'Changed room name'], ['anchors'=>json_encode(['An unrelated property'])]] as $changes) {
    $fresh = new EntityManager($connection, $orm);
    foreach ($fresh->getMetadataFactory()->getAllMetadata() as $item) $item->customRepositoryClassName=null;
    $freshRooms=new SetupRooms($fresh);
    $freshConfig=new SetupConfig($fresh->find(OnlineBookingConfig::class,$config->getId()), $fresh->find(ReservationOrigin::class,$origin->getId()), $freshRooms);
    $rejected=false;
    try { (new CastleHouseRoomService($fresh,$freshRooms,$freshConfig))->ensure([...$input,...$changes]); }
    catch (ConflictHttpException) { $rejected=true; }
    verifyRoom($rejected, 'Changed identity or unrelated property accepted.');
    verifyRoom(!$connection->isTransactionActive() && (int)$connection->fetchOne('SELECT COUNT(*) FROM appartments') === 4
        && (int)$connection->fetchOne('SELECT COUNT(*) FROM prices') === 7, 'Rejected request left a partial room or prices.');
}
echo "PASS: database-backed room/category creation, private pricing, exclusive property scope, online release, idempotent retries and CSRF/role guards.\n";
