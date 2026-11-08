<?php
require_once __DIR__ . '/RouteState.php';

/**
 * One route field contract for SQL schema, admin forms, validation and
 * named test/demo fixtures. No caller may invent widths or choice lists.
 */
final class GrTransportRouteFields
{
    public const SEED_CONFIG = 'GRTRANS_SEED_INITIALIZED';
    public const BOOL_CHOICES = ['1' => 'Yes', '0' => 'No'];

    public static function spec(): array
    {
        return [
            'id_route' => ['sql' => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'label' => 'Route ID (0 to add)', 'kind' => 'input', 'max' => 10, 'default' => '0'],
            'mode' => ['sql' => 'VARCHAR', 'max' => 16, 'label' => 'Mode', 'kind' => 'select', 'default' => 'bus', 'choices' => ['bus' => 'KTEL bus', 'ferry' => 'Ferry', 'flight' => 'Domestic flight']],
            'origin_name' => ['sql' => 'VARCHAR', 'max' => 190, 'label' => 'Origin terminal', 'kind' => 'input', 'default' => 'Athens'],
            'station_name' => ['sql' => 'VARCHAR', 'max' => 190, 'label' => 'Destination pickup station', 'kind' => 'input', 'default' => ''],
            'city' => ['sql' => 'VARCHAR', 'max' => 128, 'label' => 'City (optional)', 'kind' => 'input', 'default' => ''],
            'postal_prefix' => ['sql' => 'VARCHAR', 'max' => 5, 'label' => 'Postal prefix (optional)', 'kind' => 'input', 'default' => ''],
            'price' => ['sql' => 'DECIMAL(12,2) NOT NULL', 'max' => 16, 'max_value' => 999999, 'label' => 'Transport price EUR', 'kind' => 'input', 'default' => '0.00'],
            'pickup_only' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'label' => 'Station pickup only', 'kind' => 'select', 'default' => '1', 'choices' => self::BOOL_CHOICES],
            'route_status' => ['sql' => "VARCHAR(12) NOT NULL DEFAULT 'draft'", 'label' => 'Route state: Draft does not veto; Suspended blocks regional fallback', 'kind' => 'select', 'default' => GrTransportRouteState::DRAFT, 'choices' => GrTransportRouteState::STATE_LABELS],
            'demo' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 0', 'label' => 'Unverified demo estimate (1) / operator-confirmed (0)', 'kind' => 'select', 'default' => '1', 'choices' => self::BOOL_CHOICES],
            'verified_at' => ['sql' => 'DATETIME NULL'],
            'verified_by_employee' => ['sql' => 'INT NULL'],
            'notice' => ['sql' => 'TEXT NULL', 'max' => 2000, 'label' => 'Customer notice', 'kind' => 'textarea', 'default' => ''],
        ];
    }

    public static function limit(string $field): int
    {
        $spec = self::spec();
        if (!isset($spec[$field]['max'])) {
            throw new InvalidArgumentException('Unknown bounded route field: ' . $field);
        }
        return (int) $spec[$field]['max'];
    }

    public static function choices(string $field): array
    {
        $spec = self::spec();
        return $spec[$field]['choices'] ?? [];
    }

    public static function formFields(): array
    {
        return array_filter(self::spec(), static function ($spec) { return isset($spec['kind']); });
    }

    public static function noticeConstraintSql(): string
    {
        return 'CONSTRAINT `chk_grtrans_notice_length` CHECK (CHAR_LENGTH(`notice`) <= ' . self::limit('notice') . ')';
    }

    public static function schemaColumns(): string
    {
        $sql = [];
        foreach (self::spec() as $name => $definition) {
            $type = $definition['sql'];
            if ($type === 'VARCHAR') {
                $type = 'VARCHAR(' . (int) $definition['max'] . ') NOT NULL';
            }
            $sql[] = '`' . $name . '` ' . $type;
        }
        $sql[] = self::noticeConstraintSql();
        $sql[] = 'INDEX `lookup_prefix` (`postal_prefix`)';
        return implode(",\n            ", $sql);
    }

    /**
     * Normalize non-state fields once. RouteState::transition owns the three
     * interdependent flags and their verification provenance.
     */
    public static function normalize(array $input): array
    {
        $spec = self::spec();
        $data = [];
        foreach (['mode', 'origin_name', 'station_name', 'city', 'postal_prefix', 'notice'] as $field) {
            if (!array_key_exists($field, $input)
                || (!is_scalar($input[$field]) && !($field === 'notice' && $input[$field] === null))) {
                throw new InvalidArgumentException('Missing route field: ' . $field);
            }
            $value = trim((string) ($input[$field] ?? ''));
            $size = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($size > self::limit($field)) {
                throw new InvalidArgumentException('Route field exceeds limit: ' . $field);
            }
            $data[$field] = $value;
        }
        if (!isset($spec['mode']['choices'][$data['mode']])
            || $data['origin_name'] === '' || $data['station_name'] === ''
            || ($data['city'] === '' && $data['postal_prefix'] === '')
            || !preg_match('/^\\d{0,' . self::limit('postal_prefix') . '}$/', $data['postal_prefix'])) {
            throw new InvalidArgumentException('Invalid route location, mode or postal prefix');
        }
        if (!isset($input['price']) || !is_numeric($input['price'])
            || strlen((string) $input['price']) > self::limit('price')
            || (float) $input['price'] < 0 || (float) $input['price'] > $spec['price']['max_value']) {
            throw new InvalidArgumentException('Invalid route price');
        }
        $data['price'] = round((float) $input['price'], 2);
        return $data;
    }
}
