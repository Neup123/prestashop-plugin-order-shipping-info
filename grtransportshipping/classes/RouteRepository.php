<?php
require_once __DIR__ . '/RouteFields.php';

/**
 * Single named route mapping and checked write path used by administration,
 * initial seeding, and integration fixtures. RouteState owns all composite
 * status/demo/pickup transitions; this repository never writes them directly.
 */
final class GrTransportRouteRepository
{
    public static function prepare(array $named, ?array $old = null, int $employeeId = 0): array
    {
        $data = GrTransportRouteFields::normalize($named);
        $flags = GrTransportRouteState::transition($old, $named, $employeeId,
            $old && (float) $old['price'] !== (float) $data['price']);
        return $data + $flags;
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = Db::getInstance()->getRow('SELECT * FROM `'
            . _DB_PREFIX_ . 'grtrans_route` WHERE id_route = ' . $id);
        return is_array($row) ? $row : null;
    }

    public static function save(array $named, int $employeeId = 0, int $id = 0): int
    {
        $old = $id > 0 ? self::find($id) : null;
        if ($id > 0 && !$old) {
            throw new InvalidArgumentException('Route does not exist');
        }
        $normalized = self::prepare($named, $old, $employeeId);
        $record = [];
        foreach ($normalized as $field => $value) {
            $record[$field] = is_string($value) ? pSQL($value) : $value;
        }
        $db = Db::getInstance();
        $ok = $id > 0
            ? $db->update('grtrans_route', $record, 'id_route = ' . $id)
            : $db->insert('grtrans_route', $record);
        if (!$ok) {
            throw new RuntimeException('Route write failed');
        }
        return $id > 0 ? $id : (int) $db->Insert_ID();
    }

    public static function delete(int $id): void
    {
        if (!$id || !self::find($id)) {
            throw new InvalidArgumentException('Route does not exist');
        }
        if (!Db::getInstance()->delete('grtrans_route', 'id_route = ' . $id)) {
            throw new RuntimeException('Route delete failed');
        }
    }

    /**
     * Seed lifecycle:
     * uninitialized -> initialized-with-seed (fresh empty shop)
     * uninitialized -> initialized-with-existing-data (legacy partial shop)
     * initialized -> initialized (including after merchant deletes all rows).
     * The terminal initialized marker is never reset by a route deletion or
     * a module reinstall. Only this method sets it on a new installation.
     */
    public static function seedOnce(): void
    {
        if ((bool) Configuration::get(GrTransportRouteFields::SEED_CONFIG)) {
            return;
        }
        $db = Db::getInstance();
        $existing = (int) $db->getValue('SELECT COUNT(*) FROM `'
            . _DB_PREFIX_ . 'grtrans_route`');
        if ($existing) {
            if (!Configuration::updateValue(GrTransportRouteFields::SEED_CONFIG, 1)) {
                throw new RuntimeException('Could not record existing route dataset initialization');
            }
            return;
        }
        if (!$db->execute('START TRANSACTION')) {
            throw new RuntimeException('Could not begin initial route seed transaction');
        }
        try {
            foreach (require __DIR__ . '/../demo/routes.php' as $route) {
                self::save($route);
            }
            if (!$db->execute('COMMIT')) {
                throw new RuntimeException('Could not commit initial route dataset');
            }
        } catch (Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
        if (!Configuration::updateValue(GrTransportRouteFields::SEED_CONFIG, 1)) {
            throw new RuntimeException('Could not mark route data seed as initialized');
        }
    }
}
