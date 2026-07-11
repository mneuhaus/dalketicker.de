<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Region;

/**
 * Maps the messy, granular place names that come from the various sources
 * (sub-localities, spelling variants) onto the 13 municipalities of the
 * Kreis Gütersloh, so the place filter stays clean and dedup works across
 * sources. Unknown places are returned unchanged.
 */
final class CityNormalizer
{
    /** canonical display name => normalized prefix key (longest/most specific first). */
    private const CANON = [
        'Schloß Holte-Stukenbrock' => 'schlossholte',
        'Rheda-Wiedenbrück' => 'rheda',
        'Herzebrock-Clarholz' => 'herzebrock',
        'Gütersloh' => 'guetersloh',
        'Rietberg' => 'rietberg',
        'Harsewinkel' => 'harsewinkel',
        'Borgholzhausen' => 'borgholzhausen',
        'Steinhagen' => 'steinhagen',
        'Werther (Westf.)' => 'werther',
        'Langenberg' => 'langenberg',
        'Versmold' => 'versmold',
        'Halle (Westf.)' => 'halle',
        'Verl' => 'verl',
        'Kreis Gütersloh' => 'kreisguetersloh',
    ];

    /** Stand-alone district/locality names (no town in the string) => municipality. */
    private const ALIAS = [
        // Gütersloh
        'isselhorst' => 'Gütersloh', 'avenwedde' => 'Gütersloh', 'spexard' => 'Gütersloh',
        'friedrichsdorf' => 'Gütersloh', 'blankenhagen' => 'Gütersloh', 'niehorst' => 'Gütersloh',
        'hollen' => 'Gütersloh', 'ebbesloh' => 'Gütersloh', 'kattenstroth' => 'Gütersloh', 'pavenstaedt' => 'Gütersloh',
        // Rheda-Wiedenbrück
        'batenhorst' => 'Rheda-Wiedenbrück', 'lintel' => 'Rheda-Wiedenbrück',
        'stvit' => 'Rheda-Wiedenbrück', 'sanktvit' => 'Rheda-Wiedenbrück', 'nordrheda' => 'Rheda-Wiedenbrück',
        // Rietberg
        'druffel' => 'Rietberg', 'varensell' => 'Rietberg', 'mastholte' => 'Rietberg',
        'neuenkirchen' => 'Rietberg', 'westerwiehe' => 'Rietberg',
        // Verl
        'suerenheide' => 'Verl', 'kaunitz' => 'Verl',
        // Harsewinkel
        'marienfeld' => 'Harsewinkel', 'greffen' => 'Harsewinkel',
        // Halle (Westf.)
        'hoerste' => 'Halle (Westf.)', 'kuensebeck' => 'Halle (Westf.)', 'koelkebeck' => 'Halle (Westf.)',
        // Steinhagen
        'brockhagen' => 'Steinhagen', 'amshausen' => 'Steinhagen',
        // Borgholzhausen
        'westbarthausen' => 'Borgholzhausen', 'cleve' => 'Borgholzhausen',
        // Schloß Holte-Stukenbrock
        'stukenbrock' => 'Schloß Holte-Stukenbrock', 'sende' => 'Schloß Holte-Stukenbrock', 'liemke' => 'Schloß Holte-Stukenbrock',
        // Langenberg
        'benteler' => 'Langenberg',
        // Versmold
        'bockhorst' => 'Versmold', 'peckeloh' => 'Versmold', 'oesterweg' => 'Versmold', 'hesselteich' => 'Versmold', 'loxten' => 'Versmold',
        // Herzebrock-Clarholz
        'clarholz' => 'Herzebrock-Clarholz',
    ];

    public function normalize(?string $raw, ?Region $region = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $key = $this->key($raw);

        if ($region !== null) {
            $aliases = $region->getCityAliases();
            if (isset($aliases[$key])) {
                return $aliases[$key];
            }
            foreach ($region->getCities() as $name) {
                if (str_starts_with($key, $this->key($name))) {
                    return $name;
                }
            }
            if ($key === $this->key($region->getDefaultCity())) {
                return $region->getDefaultCity();
            }
        }

        // The static tables are Kreis-Gütersloh-specific: applied to another
        // region they mislabel places ("Verlar" near Salzkotten is not "Verl",
        // "Halle (Saale)" is not "Halle (Westf.)"). Other regions rely solely
        // on their own alias/city configuration above.
        if ($region === null || $region->getKey() === 'guetersloh') {
            if (isset(self::ALIAS[$key])) {
                return self::ALIAS[$key];
            }
            foreach (self::CANON as $name => $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return $name;
                }
            }
        }

        // Unknown (e.g. out-of-district place) — keep as given.
        return $raw;
    }

    private function key(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }
}
