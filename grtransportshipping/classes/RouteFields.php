<?php
require_once __DIR__ . '/RouteState.php';
require_once __DIR__ . '/RouteValidationException.php';

/**
 * One route field contract for SQL schema, admin forms, validation and
 * named test/demo fixtures. No caller may invent widths or choice lists.
 */
final class GrTransportRouteFields
{
    public const SEED_CONFIG = 'GRTRANS_SEED_INITIALIZED';
    public const NOTICE_CHECK_NAME = 'chk_grtrans_notice_length';
    public const BOOL_CHOICES = ['1' => 'Yes', '0' => 'No'];

    public static function spec(): array
    {
        return [
            'id_route' => ['sql' => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'],
            'mode' => ['normalize' => true, 'sql' => 'VARCHAR', 'max' => 16, 'label' => 'Mode', 'kind' => 'select', 'default' => 'bus', 'choices' => ['bus' => 'KTEL bus', 'ferry' => 'Ferry', 'flight' => 'Domestic flight']],
            'origin_name' => ['normalize' => true, 'sql' => 'VARCHAR', 'max' => 190, 'label' => 'Origin terminal', 'kind' => 'input', 'default' => 'Athens'],
            'station_name' => ['normalize' => true, 'sql' => 'VARCHAR', 'max' => 190, 'label' => 'Destination pickup station', 'kind' => 'input', 'default' => ''],
            'city' => ['normalize' => true, 'sql' => 'VARCHAR', 'max' => 128, 'label' => 'City (optional)', 'kind' => 'input', 'default' => ''],
            'postal_prefix' => ['normalize' => true, 'sql' => 'VARCHAR', 'max' => 5, 'label' => 'Postal prefix (optional)', 'kind' => 'input', 'default' => ''],
            'price' => ['sql' => 'DECIMAL(12,2) NOT NULL', 'max' => 16, 'max_value' => 100000, 'label' => 'Transport price EUR', 'kind' => 'input', 'default' => '0.00'],
            'pickup_only' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'label' => 'Station pickup only', 'help' => 'Yes requires collection from the named station; No requires arranging delivery separately.', 'kind' => 'select', 'default' => '1', 'choices' => self::BOOL_CHOICES],
            'route_status' => ['sql' => "VARCHAR(12) NOT NULL DEFAULT 'draft'", 'label' => 'Route state', 'help' => 'Draft cannot be quoted. Available can be quoted. Suspended blocks broader matching routes.', 'kind' => 'select', 'default' => GrTransportRouteState::DRAFT, 'choices' => GrTransportRouteState::STATE_LABELS],
            'demo' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 0', 'label' => 'Estimate verification', 'help' => 'Yes means the price is an unverified demo. No confirms the operator tariff and records who verified it.', 'kind' => 'select', 'default' => '1', 'choices' => self::BOOL_CHOICES],
            'verified_at' => ['sql' => 'DATETIME NULL'],
            'verified_by_employee' => ['sql' => 'INT NULL'],
            'notice' => ['normalize' => true, 'sql' => 'TEXT NULL', 'max' => 2000, 'label' => 'Customer notice', 'kind' => 'textarea', 'default' => ''],
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
        return 'CONSTRAINT `' . self::NOTICE_CHECK_NAME . '` CHECK (CHAR_LENGTH(`notice`) <= '
            . self::limit('notice') . ')';
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
        $errors = [];
        foreach ($spec as $field => $definition) {
            if (empty($definition['normalize'])) {
                continue;
            }
            $label = $definition['label'];
            if (!array_key_exists($field, $input)
                || (!is_scalar($input[$field]) && !($field === 'notice' && $input[$field] === null))) {
                $errors[$field] = $label . ' is missing or invalid.';
                continue;
            }
            $value = trim((string) ($input[$field] ?? ''));
            $size = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($size > self::limit($field)) {
                $errors[$field] = $label . ' must be at most ' . self::limit($field) . ' characters.';
            }
            $data[$field] = $value;
        }
        if (isset($data['mode']) && !isset($spec['mode']['choices'][$data['mode']])) {
            $errors['mode'] = 'Choose a valid transport mode.';
        }
        foreach (['origin_name', 'station_name'] as $required) {
            if (isset($data[$required]) && $data[$required] === '') {
                $errors[$required] = $spec[$required]['label'] . ' cannot be empty.';
            }
        }
        if (isset($data['city'], $data['postal_prefix'])
            && $data['city'] === '' && $data['postal_prefix'] === '') {
            $errors['city'] = 'Enter a city or a postal prefix.';
            $errors['postal_prefix'] = 'Enter a postal prefix or a city.';
        }
        if (isset($data['postal_prefix'])
            && !preg_match('/^\d{0,' . self::limit('postal_prefix') . '}$/', $data['postal_prefix'])) {
            $errors['postal_prefix'] = 'Postal prefix must contain only digits (up to '
                . self::limit('postal_prefix') . ').';
        }
        // Accept the European decimal comma from the admin text field,
        // while preserving the single price validator and database format.
        // Multiple separators, nonnumeric text and empty inputs still fail.
        $priceInput = isset($input['price']) && is_scalar($input['price'])
            ? trim((string) $input['price']) : '';
        $priceNumber = str_replace(',', '.', $priceInput);
        if ($priceInput === '' || strlen($priceInput) > self::limit('price')
            || !is_numeric($priceNumber) || !is_finite((float) $priceNumber)
            || round((float) $priceNumber, 2) <= 0
            || (float) $priceNumber > $spec['price']['max_value']) {
            $errors['price'] = 'Transport price EUR must be a number greater than 0 and at most '
                . $spec['price']['max_value'] . '.';
        } else {
            $data['price'] = round((float) $priceNumber, 2);
        }
        if ($errors) {
            throw new GrTransportRouteValidationException($errors);
        }
        return $data;
    }
}
