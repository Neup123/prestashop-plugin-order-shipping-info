<?php
/**
 * Shared PHP test bootstrap. One assertion helper across all smoke tests.
 * Pure tests never load PrestaShop; Docker-only tests call bootPrestaShop().
 * Browser JavaScript uses Node's native assert and is deliberately excluded.
 */
function testAssert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bootPrestaShop(bool $bootKernel = false): void
{
    require_once '/var/www/html/config/config.inc.php';
    if ($bootKernel) {
        global $kernel;
        $kernel = new AdminKernel('prod', false);
        $kernel->boot();
    }
}
