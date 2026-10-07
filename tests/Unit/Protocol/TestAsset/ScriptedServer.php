<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\TestAsset;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\InMemoryConnection;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Security;

/**
 * Builds IMAP and POP3 clients connected to a scripted in-memory server.
 *
 * The connection is plain text (Security::None) so that scripts start right
 * after the greeting; the STARTTLS tests script the upgrade themselves.
 */
final class ScriptedServer
{
    public const string HOST = 'mail.example.com';

    /**
     * A server that has sent the IMAP greeting.
     */
    public static function imapGreeting(): InMemoryConnection
    {
        return (new InMemoryConnection())->reply("* OK IMAP4rev1 ready\r\n");
    }

    /**
     * A server that has sent the POP3 greeting, with an APOP timestamp when given.
     */
    public static function pop3Greeting(string $timestamp = ''): InMemoryConnection
    {
        return (new InMemoryConnection())->reply("+OK POP3 ready {$timestamp}\r\n");
    }

    /**
     * An IMAP client connected in plain text to the scripted server; its first command is tagged TAG1.
     */
    public static function imap(InMemoryConnection $server): Imap
    {
        $imap = new Imap(connection: $server);
        $imap->connect(self::plain());

        return $imap;
    }

    /**
     * A POP3 client connected in plain text to the scripted server.
     */
    public static function pop3(InMemoryConnection $server): Pop3
    {
        $pop3 = new Pop3(connection: $server);
        $pop3->connect(self::plain());

        return $pop3;
    }

    public static function plain(): ConnectionConfig
    {
        return new ConnectionConfig(
            host: self::HOST,
            security: Security::None,
        );
    }
}
