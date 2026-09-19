<?php

namespace Astronomy;

use DateTimeZone;

/**
 * A place where someone could have been born.
 *
 * It carries the TIME ZONE as well as the coordinates, and that is the reason it exists instead
 * of passing two loose `float`s. A birth time without a time zone means nothing: «three in the
 * afternoon» is a different instant in Madrid and in Buenos Aires, and within Madrid itself it
 * is a different instant in January and in July. Without the time zone there is no chart.
 */
readonly class Place
{
    /**
     * A place on the Earth: coordinates, height and time zone.
     */
    public function __construct(
        public string $name,
        public ?string $region,
        public string $country,
        public string $countryCode,
        public float $latitude,
        public float $longitude,
        public string $timezone,
        public ?int $population = null,
    ) {}

    /**
     * How it is shown in a list of results.
     *
     * @return string
     */
    public function label(): string
    {
        return implode(', ', array_filter([$this->name, $this->region, $this->country]));
    }

    /**
     * @return DateTimeZone
     */
    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /**
     * Coordinates as they are written on a chart: 40° 25' N 3° 42' O.
     *
     * @return string
     */
    public function coordinates(): string
    {
        $format = function (float $degrees, string $positive, string $negative): string {
            $hemisphere = $degrees >= 0 ? $positive : $negative;
            $degrees = abs($degrees);
            $whole = (int) floor($degrees);

            return sprintf('%d° %02d\' %s', $whole, (int) round(($degrees - $whole) * 60), $hemisphere);
        };

        return $format($this->latitude, 'N', 'S').' '.$format($this->longitude, 'E', 'O');
    }

    /**
     * To put it in a hidden form field and get it back without asking again.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'region' => $this->region,
            'country' => $this->country,
            'countryCode' => $this->countryCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timeZone' => $this->timezone,
            'population' => $this->population,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self|null
     */
    public static function fromArray(?array $data): ?self
    {
        if (! is_array($data) || ! isset($data['latitude'], $data['longitude'], $data['timeZone'])) {
            return null;
        }

        // The time zone arrives from the browser through a hidden field, so it has to be
        // checked for being a real one: `new DateTimeZone('whatever')` throws, and an
        // exception here would be a 500 error on a public form.
        if (! in_array($data['timeZone'], DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return new self(
            name: (string) ($data['name'] ?? ''),
            region: $data['region'] ?? null,
            country: (string) ($data['country'] ?? ''),
            countryCode: (string) ($data['countryCode'] ?? ''),
            latitude: (float) $data['latitude'],
            longitude: (float) $data['longitude'],
            timezone: (string) $data['timeZone'],
            population: isset($data['population']) ? (int) $data['population'] : null,
        );
    }
}
