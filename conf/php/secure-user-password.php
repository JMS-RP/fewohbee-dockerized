<?php

declare(strict_types=1);

// Keep FewohBee's account validation, password hashing and role selection.
// Only change its command-line password question to hidden input.
$path = $argv[1] ?? '/var/www/html/src/Command/CreateUserCommand.php';
$source = file_get_contents($path);
if (false === $source) {
    throw new RuntimeException('Cannot read FewohBee account creation command.');
}

$visiblePrompt = <<<'PHP'
        $password = $io->ask('Password (min 10 characters)', null, function ($input) {
            $this->us->isPasswordValid($input, new User());

            return $input;
        });
PHP;
$hiddenPrompt = <<<'PHP'
        $passwordQuestion = new \Symfony\Component\Console\Question\Question('Password (min 10 characters)');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $passwordQuestion->setValidator(function ($input) {
            $this->us->isPasswordValid($input, new User());

            return $input;
        });
        $password = $io->askQuestion($passwordQuestion);
PHP;

if (1 === substr_count($source, $hiddenPrompt)) {
    echo "FewohBee account password input is already hidden.\n";
    exit(0);
}
if (1 !== substr_count($source, $visiblePrompt)) {
    throw new RuntimeException('Upstream account creation changed. Review the hidden password customization before deploying.');
}
if (false === file_put_contents($path, str_replace($visiblePrompt, $hiddenPrompt, $source))) {
    throw new RuntimeException('Cannot write the hidden password customization.');
}
echo "FewohBee account password input is now hidden.\n";
