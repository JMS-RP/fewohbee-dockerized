<?php

declare(strict_types=1);

// Refuse an unfamiliar upstream implementation instead of silently changing
// unrelated validation. Country and the guest's identity remain required.
$path = $argv[1] ?? '/var/www/html/src/Service/OnlineBooking/PublicBookingService.php';
$source = file_get_contents($path);
if (false === $source) {
    throw new RuntimeException('Cannot read FewohBee public booking validation.');
}

$requiredAddress = <<<'PHP'
        if (
            '' === self::sanitize($booker['address'] ?? '')
            || '' === self::sanitize($booker['zip'] ?? '')
            || '' === self::sanitize($booker['city'] ?? '')
            || '' === self::sanitize($booker['country'] ?? '')
        ) {
            throw new PublicBookingException('online_booking.error.booker_required');
        }
PHP;

$optionalAddress = <<<'PHP'
        // Castle House does not collect postal addresses in its guest booking form.
        if ('' === self::sanitize($booker['country'] ?? '')) {
            throw new PublicBookingException('online_booking.error.booker_required');
        }
PHP;

if (1 === substr_count($source, $optionalAddress)) {
    echo "Castle House booking address is already optional.\n";
    exit(0);
}
if (1 !== substr_count($source, $requiredAddress)) {
    throw new RuntimeException('Upstream booking validation changed. Review the Castle House address customization before deploying.');
}
if (false === file_put_contents($path, str_replace($requiredAddress, $optionalAddress, $source))) {
    throw new RuntimeException('Cannot write the Castle House booking customization.');
}
echo "Castle House booking address is now optional.\n";
