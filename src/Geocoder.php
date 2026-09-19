<?php

namespace Astronomy;

/**
 * Look up a place by its name.
 *
 * It is an interface and not a class because choosing a provider is not a technical
 * decision, it is a licensing one. The place data that serves for this comes almost all from
 * GeoNames, and every provider hands it out under its own terms: some allow commercial use
 * and others do not. With this behind a contract, switching provider the day the terms
 * change is one line in the configuration, not rewriting the form.
 */
interface Geocoder
{
    /**
     * Places matching what the user typed, from the most likely to the least.
     *
     * Returns an empty list if there is nothing or if the provider fails: a city search box
     * that blows up the page when the provider is having a bad day is worse than one that
     * says "I found nothing".
     *
     * @param string $query
     * @param int $limit
     * @return list<Place>
     */
    public function find(string $query, int $limit = 8): array;
}
