<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Exception\PublicBookingException;
use App\Repository\CustomerRepository;
use App\Service\OnlineBooking\PublicBookingService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;

class BookingTestRepository extends CustomerRepository
{
    public ?Customer $existing = null;
    public function __construct() {}
    public function findOneByEmailCaseInsensitive(string $email): ?Customer { return $this->existing; }
}

class BookingTestManager extends EntityManager
{
    public BookingTestRepository $repository;
    public array $saved = [];
    public function __construct() { $this->repository = new BookingTestRepository(); }
    public function getRepository(string $className): EntityRepository { return $this->repository; }
    public function persist(object $object): void { $this->saved[] = $object; }
    public function flush(): void {}
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$reflection = new ReflectionClass(PublicBookingService::class);
$service = $reflection->newInstanceWithoutConstructor();
$manager = new BookingTestManager();
$reflection->getProperty('em')->setValue($service, $manager);
$book = $reflection->getMethod('findOrCreateBookerCustomer');
$details = ['salutation' => 'Mr', 'firstname' => 'Test', 'lastname' => 'Guest',
            'email' => 'guest@example.test', 'country' => 'GB'];

// Exercise the actual method in the customized image, without a database or network.
$customer = $book->invoke($service, $details);
$address = $customer->getCustomerAddresses()->first();
check($customer->getLastname() === 'Guest', 'The guest was not created.');
check($address->getAddress() === null && $address->getZip() === null && $address->getCity() === null,
      'Missing postal details must remain null.');
check($address->getCountry() === 'GB' && $address->getEmail() === 'guest@example.test', 'Contact details were lost.');
check(count($manager->saved) === 2, 'The guest and contact record were not persisted.');

foreach ([['country' => ''], ['firstname' => ''], ['lastname' => ''], ['salutation' => ''],
          ['email' => ''], ['email' => 'not-an-email']] as $invalid) {
    $rejected = false;
    try { $book->invoke($service, array_replace($details, $invalid)); }
    catch (PublicBookingException $error) { $rejected = true; }
    check($rejected, 'Removing postal fields weakened required identity, email or country validation.');
}

// Address-free enquiries must not overwrite an owner's existing customer records.
$curated = new Customer();
$curated->setSalutation('Mr');
$curated->setFirstname('Test');
$curated->setLastname('Guest');
$savedAddress = new CustomerAddresses();
$savedAddress->setEmail('guest@example.test');
$savedAddress->setAddress('Existing address');
$savedAddress->setZip('Existing postcode');
$savedAddress->setCity('Existing town');
$savedAddress->setCountry('GB');
$curated->addCustomerAddress($savedAddress);
$manager->repository->existing = $curated;
check($book->invoke($service, $details) === $curated, 'The existing guest was not reused.');
check($savedAddress->getAddress() === 'Existing address' && $savedAddress->getZip() === 'Existing postcode'
    && $savedAddress->getCity() === 'Existing town', 'Existing postal details were overwritten.');

echo "PASS: address-free guest creation, null postal details, identity/email/country validation and existing address preservation. No database or network used.\n";
