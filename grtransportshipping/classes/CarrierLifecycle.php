<?php
/**
 * One carrier identity and install/recovery state machine.
 *
 * PrestaShop itself may externally disable/delete/replace a carrier in the
 * back office. We observe those transitions; we do NOT auto-create carriers
 * during customer quotes. All module-owned identity writes go through adopt().
 *
 * unconfigured / missing / foreign / deleted / disabled / orphaned
 *       -- ensure() during install or explicit admin repair --> ready
 * ready -- PrestaShop carrier edit (actionCarrierUpdate) --> ready (new ID)
 * ready -- external disable/delete --> disabled/deleted (quote denied)
 * ready -- uninstall --> unconfigured (owned carrier soft-deleted)
 *
 * "ready" is the only quoting state. No implicit background transitions.
 */
final class GrTransportCarrierLifecycle
{
    public const CONFIG = 'GRTRANS_CARRIER';
    public const MODULE = 'grtransportshipping';
    public const UNCONFIGURED = 'unconfigured';
    public const MISSING = 'missing';
    public const FOREIGN = 'foreign';
    public const DELETED = 'deleted';
    public const DISABLED = 'disabled';
    public const ORPHANED = 'orphaned';
    public const READY = 'ready';
    public const DEFAULT_NAME = 'Greek transport — station pickup';
    // PrestaShop carrier.name is not language-specific; show both languages
    // to customers without overwriting a merchant-customized carrier name.
    public const BILINGUAL_NAME = 'Greek transport — station pickup / Παραλαβή από σταθμό';
    public const DEFAULT_DELAY = 'Collection from the named transport station (not home delivery)';

    public static function isOwned(int $carrierId): bool
    {
        if ($carrierId <= 0) {
            return false;
        }
        $carrier = new Carrier($carrierId);
        return Validate::isLoadedObject($carrier)
            && (string) $carrier->external_module_name === self::MODULE;
    }

    /** The only reader of the configured carrier identity. */
    public static function inspect(): array
    {
        $id = (int) Configuration::get(self::CONFIG);
        if ($id <= 0) {
            $candidate = self::findReusable();
            return ['state' => $candidate ? self::ORPHANED : self::UNCONFIGURED, 'id' => $candidate];
        }
        $carrier = new Carrier($id);
        if (!Validate::isLoadedObject($carrier)) {
            return ['state' => self::MISSING, 'id' => $id];
        }
        if ((string) $carrier->external_module_name !== self::MODULE) {
            return ['state' => self::FOREIGN, 'id' => $id];
        }
        if (!empty($carrier->deleted)) {
            return ['state' => self::DELETED, 'id' => $id];
        }
        if (empty($carrier->active)) {
            return ['state' => self::DISABLED, 'id' => $id];
        }
        return ['state' => self::READY, 'id' => $id];
    }

    /**
     * Keep CarrierModule's platform-required $id_carrier property synchronized
     * with the live identity, without treating it as a second source of truth.
     */
    public static function activeId($module): int
    {
        $state = self::inspect();
        $id = $state['state'] === self::READY ? (int) $state['id'] : 0;
        $module->id_carrier = $id;
        return $id;
    }

    /**
     * Idempotent install or explicit repair: reuse a complete/partial owned
     * carrier where possible, repair missing zone/range/groups, create only
     * when no reusable owned carrier exists.
     */
    public static function ensure($module): bool
    {
        $state = self::inspect();
        if ($state['state'] === self::READY) {
            $carrier = new Carrier((int) $state['id']);
            return self::complete($carrier) && self::adopt($module, (int) $carrier->id)
                && self::localizeDefaults($module);
        }
        $candidateId = self::findReusable();
        if ($candidateId > 0) {
            $carrier = new Carrier($candidateId);
            if (empty($carrier->active)) {
                $carrier->active = true;
                if (!$carrier->save()) {
                    return false;
                }
            }
            return self::complete($carrier) && self::adopt($module, $candidateId)
                && self::localizeDefaults($module);
        }
        return self::create($module);
    }

    /**
     * Only the platform's carrier-clone hook may substitute a new carrier ID
     * without manual repair. Validate ownership before accepting the new ID.
     */
    public static function onCarrierUpdate($module, int $oldId, $replacement): void
    {
        $state = self::inspect();
        if ($oldId <= 0 || $oldId !== (int) $state['id']
            || !$replacement || !isset($replacement->id)
            || !self::isOwned((int) $replacement->id)) {
            return;
        }
        $newCarrier = new Carrier((int) $replacement->id);
        if (!empty($newCarrier->deleted) || empty($newCarrier->active)) {
            return;
        }
        if (!self::adopt($module, (int) $newCarrier->id)) {
            throw new RuntimeException('Carrier replacement identity was not persisted');
        }
    }

    /**
     * Upgrade/repair localization of only original stock carrier text. The
     * carrier name is global in PrestaShop; the bilingual default is explicit
     * rather than pretending that it changes by customer locale. Delay is a
     * native per-language field. Never replace merchant-edited values.
     */
    public static function localizeDefaults($module): bool
    {
        $state = self::inspect();
        $id = (int) $state['id'];
        if (!self::isOwned($id)) {
            return true; // No carrier to localize; repair is a separate action.
        }
        $carrier = new Carrier($id);
        $changed = false;
        if ((string) $carrier->name === self::DEFAULT_NAME) {
            $carrier->name = self::BILINGUAL_NAME;
            $changed = true;
        }
        foreach (Language::getLanguages(false) as $language) {
            $langId = (int) $language['id_lang'];
            $iso = strtolower((string) $language['iso_code']);
            if ($iso !== 'el' || !isset($carrier->delay[$langId])
                || (string) $carrier->delay[$langId] !== self::DEFAULT_DELAY) {
                continue;
            }
            $translated = $module->l(self::DEFAULT_DELAY, self::MODULE, $iso);
            if ($translated !== self::DEFAULT_DELAY) {
                $carrier->delay[$langId] = $translated;
                $changed = true;
            }
        }
        return !$changed || $carrier->update();
    }

    public static function retire($module): bool
    {
        $state = self::inspect();
        $id = (int) $state['id'];
        if (self::isOwned($id)) {
            $carrier = new Carrier($id);
            $carrier->deleted = true;
            if (!$carrier->save()) {
                return false;
            }
        }
        Configuration::deleteByName(self::CONFIG);
        $module->id_carrier = 0;
        return true;
    }

    private static function adopt($module, int $id): bool
    {
        if ($id <= 0 || !self::isOwned($id) || !Configuration::updateValue(self::CONFIG, $id)) {
            return false;
        }
        $module->id_carrier = $id;
        return true;
    }

    private static function findReusable(): int
    {
        $rows = Db::getInstance()->executeS('SELECT id_carrier, active FROM `'
            . _DB_PREFIX_ . 'carrier` WHERE external_module_name = \''
            . pSQL(self::MODULE) . '\' AND deleted = 0 ORDER BY active DESC, id_carrier DESC') ?: [];
        foreach ($rows as $row) {
            if (self::isOwned((int) $row['id_carrier'])) {
                return (int) $row['id_carrier'];
            }
        }
        return 0;
    }

    /** Repair incomplete carrier setup without duplicating ranges/zones. */
    private static function complete(Carrier $carrier): bool
    {
        if (!Validate::isLoadedObject($carrier) || !self::isOwned((int) $carrier->id)) {
            return false;
        }
        $ranges = RangeWeight::getRanges((int) $carrier->id);
        if (!$ranges) {
            $range = new RangeWeight();
            $range->id_carrier = (int) $carrier->id;
            $range->delimiter1 = 0;
            $range->delimiter2 = 100000;
            if (!$range->add()) {
                return false;
            }
            $ranges = RangeWeight::getRanges((int) $carrier->id);
        }
        $gr = (int) Country::getByIso('GR');
        if ($gr > 0) {
            $country = new Country($gr);
            $zone = (int) $country->id_zone;
            $zones = Db::getInstance()->executeS('SELECT id_zone FROM `'
                . _DB_PREFIX_ . 'carrier_zone` WHERE id_carrier = ' . (int) $carrier->id) ?: [];
            $zoneIds = array_map(static function ($row) { return (int) $row['id_zone']; }, $zones);
            if (!in_array($zone, $zoneIds, true) && !$carrier->addZone($zone)) {
                return false;
            }
            foreach ($ranges as $range) {
                $idRange = (int) $range['id_range_weight'];
                $existing = Db::getInstance()->executeS('SELECT id_delivery FROM `'
                    . _DB_PREFIX_ . 'delivery` WHERE id_carrier = ' . (int) $carrier->id
                    . ' AND id_zone = ' . $zone . ' AND id_range_weight = ' . $idRange) ?: [];
                if (!$existing && !Db::getInstance()->insert('delivery', [
                    'id_carrier' => (int) $carrier->id,
                    'id_range_price' => 0,
                    'id_range_weight' => $idRange,
                    'id_zone' => $zone,
                    'price' => 0,
                ])) {
                    return false;
                }
            }
        }
        $existingGroups = Db::getInstance()->executeS('SELECT id_group FROM `'
            . _DB_PREFIX_ . 'carrier_group` WHERE id_carrier = ' . (int) $carrier->id) ?: [];
        if ($existingGroups) {
            return true; // Preserve deliberate merchant group restrictions.
        }
        $groups = [];
        foreach (Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT')) as $group) {
            $groups[] = (int) $group['id_group'];
        }
        return $carrier->setGroups($groups);
    }

    private static function create($module): bool
    {
        $carrier = new Carrier();
        $carrier->name = self::BILINGUAL_NAME;
        $carrier->active = true;
        $carrier->deleted = false;
        $carrier->shipping_external = true;
        $carrier->external_module_name = self::MODULE;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
        $carrier->is_module = true;
        $carrier->need_range = true;
        $carrier->range_behavior = 0;
        $carrier->grade = 0;
        $carrier->delay = [];
        foreach (Language::getLanguages(false) as $language) {
            $carrier->delay[(int) $language['id_lang']] =
                $module->l(self::DEFAULT_DELAY, self::MODULE, (string) $language['iso_code']);
        }
        if (!$carrier->add()) {
            return false;
        }
        // Identity is set before accessories; a retry reuses this partial
        // creation rather than creating another carrier.
        return self::adopt($module, (int) $carrier->id) && self::complete($carrier);
    }
}
