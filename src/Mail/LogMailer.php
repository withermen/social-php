<?php

namespace Social\Mail;

final class LogMailer implements MailerInterface
{
    public function __construct(private readonly string $path, private readonly string $from)
    {
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        $entry = '[' . gmdate('Y-m-d H:i:s') . ']' . PHP_EOL
            . 'From: ' . $this->from . PHP_EOL
            . 'To: ' . $to . PHP_EOL
            . 'Subject: ' . $subject . PHP_EOL
            . PHP_EOL
            . $body . PHP_EOL
            . str_repeat('-', 60) . PHP_EOL;

        file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX);
    }
}
