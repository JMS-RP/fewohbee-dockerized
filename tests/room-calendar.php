<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use App\Controller\CastleHouseCalendarController;
use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\RoomCategory;
use App\Repository\AppartmentRepository;
use App\Service\AvailabilityService;
use App\Service\CastleHouseCalendarService;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use App\Service\OnlineBooking\PublicBookingCalendarService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CalendarRooms extends AppartmentRepository
{
    public array $rooms=[];
    public function __construct() {}
    public function findForPublicBooking(array $roomIds,array $subsidiaryIds): array { return $this->rooms; }
}
class CalendarOccupancy extends AvailabilityService
{
    public array $occupied=[];
    public int $reads=0;
    public function __construct() {}
    public function getOccupiedNightsForRoom(Appartment $room,DateTimeImmutable $from,DateTimeImmutable $toExclusive): array
    {
        ++$this->reads;
        return $this->occupied[(int)$room->getId()] ?? [];
    }
}
class CalendarConfig extends OnlineBookingConfigService
{
    private OnlineBookingConfig $config;
    public function __construct() { $this->config=(new OnlineBookingConfig())->setEnabled(true); }
    public function getConfig(): OnlineBookingConfig { return $this->config; }
    public function getAllowedRoomIds(?OnlineBookingConfig $config=null): array { return [1,2,3]; }
    public function getAllowedSubsidiaryIds(?OnlineBookingConfig $config=null): array { return [1]; }
}
class CalendarHorizon extends PublicBookingCalendarService
{
    public DateTimeImmutable $end;
    public function __construct() { $this->end=(new DateTimeImmutable('today'))->modify('+24 months'); }
    public function getHorizonEnd(): DateTimeImmutable { return $this->end; }
}
function checkCalendar(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$rooms=new CalendarRooms();
foreach (['Dudley Room','Stanley Room','Gee Room'] as $i=>$name) {
    $category=(new RoomCategory())->setName($name);
    $room=(new Appartment())->setId($i+1)->setActive(true)->setRoomCategory($category);
    $rooms->rooms[]=$room;
}
$occupancy=new CalendarOccupancy(); $config=new CalendarConfig(); $horizon=new CalendarHorizon();
$service=new CastleHouseCalendarService($rooms,$occupancy,$config,$horizon);
$first=(new DateTimeImmutable('first day of next month'))->setTime(0,0);
$month=$first->format('Y-m');
$busy=$first->modify('+3 days')->format('Y-m-d');
$occupancy->occupied[1]=[$busy=>true];
$result=$service->month($month);
checkCalendar(count($result['rooms'])===3,'Not all released rooms returned.');
checkCalendar($result['rooms'][0]['label']==='Dudley Room','Wrong public room label.');
checkCalendar($result['rooms'][0]['nights'][$busy]===false,'Occupied night shown as free.');
checkCalendar($result['rooms'][0]['nights'][$first->modify('+4 days')->format('Y-m-d')]===true,'Checkout boundary did not reopen the room.');
checkCalendar(count($result['rooms'][0]['nights'])===(int)$first->format('t'),'Incomplete calendar month.');
checkCalendar($occupancy->reads===3,'Occupancy must be loaded once per room, not once per date.');
checkCalendar(!str_contains(json_encode($result),'guest') && !str_contains(json_encode($result),'email'),'Guest information leaked into calendar.');

$horizon->end=$first->modify('+10 days');
$limited=$service->month($month);
checkCalendar($limited['rooms'][0]['nights'][$horizon->end->format('Y-m-d')]===false,'Booking horizon was ignored.');
$rooms->rooms[2]->setActive(false);
$inactive=$service->month($month);
checkCalendar(!in_array(true,$inactive['rooms'][2]['nights'],true),'Inactive room shown as free.');
foreach (['','2026-99','1900-01','2999-01'] as $invalid) {
    $rejected=false;
    try { $service->month($invalid); } catch (BadRequestHttpException) { $rejected=true; }
    checkCalendar($rejected,'Invalid month was accepted.');
}
$controller=new CastleHouseCalendarController();
$attributes=(new ReflectionClass($controller))->getAttributes(IsGranted::class);
checkCalendar($attributes[0]->getArguments()[0]==='ROLE_RESERVATIONS_RO','Calendar lost its reservation read permission.');
$route=(new ReflectionMethod($controller,'month'))->getAttributes(Route::class)[0]->getArguments();
checkCalendar($route['methods']===['GET'],'Calendar endpoint must remain read-only.');
$response=$controller->month(Request::create('/reservation/castle-house/calendar?month='.$month),$service);
checkCalendar($response->getStatusCode()===200 && str_contains($response->headers->get('Cache-Control'),'no-store'),'Private calendar caching is unsafe.');
echo "PASS: canonical occupancy, checkout boundary, horizon, released/active rooms, full month, private read-only route and no guest data.\n";
