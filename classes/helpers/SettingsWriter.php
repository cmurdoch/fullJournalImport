<?php
/**
 * @file classes/helpers/SettingsWriter.php
 *
 * @brief Writes structured settings arrays back into OJS *_settings tables.
 * Inverse of the export SettingsHelper.
 *
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */

namespace APP\plugins\importexport\fullJournalImport\classes\helpers;

use Illuminate\Support\Facades\DB;

class SettingsWriter
{
    public static function writeSettings(string $table, string $idColumn, int|string $idValue, array $settings): void
    {
        if (empty($settings)) {
            return;
        }

        $rows = [];

        foreach ($settings as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $locale => $localisedValue) {
                    $rows[] = [
                        $idColumn => $idValue,
                        'locale' => $locale,
                        'setting_name' => $name,
                        'setting_value' => $localisedValue,
                    ];
                }
            } else {
                $rows[] = [
                    $idColumn => $idValue,
                    'locale' => '',
                    'setting_name' => $name,
                    'setting_value' => $value,
                ];
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}