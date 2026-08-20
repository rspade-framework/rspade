<?php

namespace App\RSpade\Commands\Database;

use Illuminate\Console\Command;
use Sokil\IsoCodes\IsoCodesFactory;
use App\RSpade\Core\Models\Country_Model;
use App\RSpade\Core\Models\Region_Model;

/**
 * Seed geographic data (countries and regions) from ISO 3166 standards
 *
 * Uses sokil/php-isocodes library to populate countries (ISO 3166-1)
 * and regions/subdivisions (ISO 3166-2) tables.
 */
#[Instantiatable]
class Seed_Geographic_Data_Command extends Command
{
    protected $signature = 'rsx:seed:geographic-data';

    protected $description = 'Import countries and regions from ISO 3166 standards';

    public function handle()
    {
        $this->info('Importing geographic data from ISO 3166 standards...');
        $this->newLine();

        // Initialize ISO codes library
        $isoCodes = new IsoCodesFactory();

        // Seed countries
        $this->seedCountries($isoCodes);

        // Seed regions
        $this->seedRegions($isoCodes);

        $this->newLine();
        $this->info('Geographic data import completed successfully!');

        return Command::SUCCESS;
    }

    private function seedCountries(IsoCodesFactory $isoCodes)
    {
        $this->info('[Countries] Loading ISO 3166-1 data...');

        $countries = $isoCodes->getCountries();
        $imported_alpha2_codes = [];
        $added = 0;
        $updated = 0;

        foreach ($countries as $country) {
            $alpha2 = $country->getAlpha2();
            $imported_alpha2_codes[] = $alpha2;

            $existing = Country_Model::where('alpha2', $alpha2)->first();

            if ($existing) {
                $existing->alpha2 = $alpha2;
                $existing->alpha3 = $country->getAlpha3();
                $existing->numeric = $country->getNumericCode();
                $existing->name = $country->getName();
                $existing->common_name = $country->getLocalName() !== $country->getName()
                    ? $country->getLocalName()
                    : null;
                $existing->enabled = true;
                $existing->save();
                $updated++;
            } else {
                $model = new Country_Model();
                $model->alpha2 = $alpha2;
                $model->alpha3 = $country->getAlpha3();
                $model->numeric = $country->getNumericCode();
                $model->name = $country->getName();
                $model->common_name = $country->getLocalName() !== $country->getName()
                    ? $country->getLocalName()
                    : null;
                $model->enabled = true;
                $model->save();
                $added++;
            }
        }

        // Disable countries not in import
        $disabled = Country_Model::whereNotIn('alpha2', $imported_alpha2_codes)
            ->where('enabled', true)
            ->update(['enabled' => false]);

        $this->line("[Countries] Added: {$added}, Updated: {$updated}, Disabled: {$disabled}");
    }

    private function seedRegions(IsoCodesFactory $isoCodes)
    {
        $this->info('[Regions] Loading ISO 3166-2 subdivisions...');

        $subdivisions = $isoCodes->getSubdivisions();
        $imported_keys = [];
        $added = 0;
        $updated = 0;

        foreach ($subdivisions as $subdivision) {
            $code = $subdivision->getCode();

            // Extract country code from subdivision code (e.g., "US-CA" -> "US")
            $parts = explode('-', $code);
            if (count($parts) < 2) {
                // Skip malformed codes
                continue;
            }

            $country_alpha2 = $parts[0];

            // Only import if country exists
            if (!Country_Model::where('alpha2', $country_alpha2)->exists()) {
                continue;
            }

            $imported_keys[] = $country_alpha2 . '|' . $code;

            $existing = Region_Model::where('country_alpha2', $country_alpha2)
                ->where('code', $code)
                ->first();

            if ($existing) {
                $existing->code = $code;
                $existing->country_alpha2 = $country_alpha2;
                $existing->name = $subdivision->getName();
                $existing->type = $subdivision->getType();
                $existing->enabled = true;
                $existing->save();
                $updated++;
            } else {
                $model = new Region_Model();
                $model->code = $code;
                $model->country_alpha2 = $country_alpha2;
                $model->name = $subdivision->getName();
                $model->type = $subdivision->getType();
                $model->enabled = true;
                $model->save();
                $added++;
            }
        }

        // Disable regions not in import. Walked a page at a time - the ISO subdivision
        // set runs to several thousand rows.
        $disabled = 0;

        foreach (Region_Model::where('enabled', true)->result_set() as $region) {
            $key = $region->country_alpha2 . '|' . $region->code;
            if (!in_array($key, $imported_keys)) {
                $region->enabled = false;
                $region->save();
                $disabled++;
            }
        }

        $this->line("[Regions] Added: {$added}, Updated: {$updated}, Disabled: {$disabled}");
    }
}
