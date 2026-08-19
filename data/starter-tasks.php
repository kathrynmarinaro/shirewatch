<?php
/* The generic starter maintenance list.
 *
 * THE BRIEF ASKED FOR A GENERIC LIST, NOT A SETUP WIZARD. Nothing here is
 * inferred from the house, nothing asks a question, and everything is fully
 * editable and deletable — you delete what doesn't apply rather than
 * answering twenty questions to avoid seeing it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A FILE AND NOT `INSERT` ROWS IN schema.sql.
 * ---------------------------------------------------------------------------
 *
 * maintenance_tasks.next_due_on is NOT NULL, and there is no sensible literal
 * to put there: a fixed date in the schema means every fresh install opens on
 * twenty tasks that are all wildly overdue, which is exactly the impression
 * that gets an app deleted in its first minute. The dates have to be computed
 * forward from the day of the install, and that needs the recurrence engine —
 * so it needs PHP.
 *
 * tools/install-starter-tasks.php is what reads this. It is idempotent on
 * title, so running it twice adds nothing.
 *
 * ---------------------------------------------------------------------------
 * THE `months` ONES ARE NOT AN AFFECTATION.
 * ---------------------------------------------------------------------------
 *
 * Gutters in March and October, the sprinkler blowout in October, storm
 * windows in October and April. Written as "every 6 months" they would drift
 * out of season within a few years — a blowout that wanders into November is
 * a burst pipe. Everything wear-based is an interval; everything weather-based
 * is month-anchored. That distinction is the whole reason the schema carries
 * both (DELEGATION-PLAN.md §2.2).
 *
 * `location` and `category` name SEEDED TAGS. A name that matches nothing is
 * skipped with a warning rather than created — a typo here should not quietly
 * grow a 25th room.
 */

declare(strict_types=1);

return array(

    /* ---- house-wide ------------------------------------------------- */

    array(
        'title'        => 'Change HVAC filter',
        'instructions' => "Check the size printed on the old one before buying.\nIf anyone's allergies are bad, do it monthly instead.",
        'recur_kind'   => 'interval',
        'interval'     => array(3, 'month'),
        'category'     => 'HVAC',
    ),
    array(
        'title'        => 'Test smoke and CO detectors',
        'instructions' => "Hold the test button until it sounds. Replace batteries yearly even if they still test fine — a detector chirping at 3am is the alternative.",
        'recur_kind'   => 'interval',
        'interval'     => array(6, 'month'),
        'category'     => 'Safety',
    ),
    array(
        'title'        => 'Replace smoke detector batteries',
        'instructions' => 'All of them at once, so they never come due separately.',
        'recur_kind'   => 'months',
        'months'       => '11',
        'day'          => 1,
        'category'     => 'Safety',
    ),
    array(
        'title'        => 'Flush water heater',
        'instructions' => "Drains the sediment that makes it work harder and fail sooner. If it has never been done and the heater is old, read up first — sometimes the valve is what fails.",
        'recur_kind'   => 'interval',
        'interval'     => array(1, 'year'),
        'category'     => 'Plumbing',
    ),
    array(
        'title'        => 'Clean gutters',
        'instructions' => "After the leaves are down in autumn, and again before spring rain.",
        'recur_kind'   => 'months',
        'months'       => '3,10',
        'day'          => 15,
        'location'     => 'Gutters',
        'category'     => 'Roofing',
    ),
    array(
        'title'        => 'Inspect roof',
        'instructions' => "From the ground with binoculars is enough: missing or lifted shingles, anything growing, flashing around the chimney.",
        'recur_kind'   => 'months',
        'months'       => '4',
        'day'          => 1,
        'location'     => 'Roof',
        'category'     => 'Roofing',
    ),
    array(
        'title'        => 'Sweep and inspect chimney',
        'instructions' => 'Before the first fire of the season, not after.',
        'recur_kind'   => 'months',
        'months'       => '9',
        'day'          => 15,
        'location'     => 'Chimney',
        'category'     => 'Safety',
    ),
    array(
        'title'        => 'Pump septic tank',
        'instructions' => "Every 3-5 years for a typical household. Note the date here even when the company says they will remind you.",
        'recur_kind'   => 'interval',
        'interval'     => array(3, 'year'),
        'location'     => 'Septic Tank',
        'category'     => 'Septic',
    ),
    array(
        'title'        => 'Test sump pump',
        'instructions' => 'Pour a bucket of water into the pit and watch it kick on.',
        'recur_kind'   => 'months',
        'months'       => '3,9',
        'day'          => 1,
        'category'     => 'Plumbing',
    ),
    array(
        'title'        => 'Blow out sprinkler lines',
        'instructions' => 'Before the first hard freeze. This one is not worth being late for.',
        'recur_kind'   => 'months',
        'months'       => '10',
        'day'          => 20,
        'location'     => 'Yard',
        'category'     => 'Landscaping',
    ),
    array(
        'title'        => 'Check caulking and exterior seals',
        'instructions' => 'Around windows, doors, and where pipes enter the house.',
        'recur_kind'   => 'interval',
        'interval'     => array(1, 'year'),
        'category'     => 'Exterior',
    ),
    array(
        'title'        => 'Clean dryer vent duct',
        'instructions' => "The whole run to the outside, not just the lint trap. This is a fire risk, not a housekeeping one.",
        'recur_kind'   => 'interval',
        'interval'     => array(1, 'year'),
        'location'     => 'Laundry Room',
        'category'     => 'Safety',
    ),
    array(
        'title'        => 'Test GFCI outlets',
        'instructions' => 'Press Test, confirm it cuts power, press Reset. Kitchen, bathrooms, garage, outdoors.',
        'recur_kind'   => 'interval',
        'interval'     => array(6, 'month'),
        'category'     => 'Electrical',
    ),

    /* ---- appliances -------------------------------------------------- */

    array(
        'title'        => 'Vacuum refrigerator coils',
        'instructions' => 'Behind or underneath. Dusty coils are most of why a fridge runs constantly.',
        'recur_kind'   => 'interval',
        'interval'     => array(6, 'month'),
        'location'     => 'Kitchen',
        'category'     => 'Appliances',
    ),
    array(
        'title'        => 'Clean dishwasher filter',
        'instructions' => 'Twists out of the floor of the tub. Rinse it under the tap.',
        'recur_kind'   => 'interval',
        'interval'     => array(3, 'month'),
        'location'     => 'Kitchen',
        'category'     => 'Appliances',
    ),
    array(
        'title'        => 'Clean range hood filter',
        'instructions' => 'Dishwasher or hot soapy water.',
        'recur_kind'   => 'interval',
        'interval'     => array(3, 'month'),
        'location'     => 'Kitchen',
        'category'     => 'Appliances',
    ),
    array(
        'title'        => 'Check washer hoses',
        'instructions' => "Looking for bulges, cracks or rust at the couplings. Replace them every five years whether or not they look bad — a burst hose empties into the house at mains pressure.",
        'recur_kind'   => 'interval',
        'interval'     => array(1, 'year'),
        'location'     => 'Laundry Room',
        'category'     => 'Appliances',
    ),
    array(
        'title'        => 'Clean garbage disposal',
        'instructions' => 'Ice and coarse salt, then citrus peel.',
        'recur_kind'   => 'interval',
        'interval'     => array(3, 'month'),
        'location'     => 'Kitchen',
        'category'     => 'Appliances',
    ),
    array(
        'title'        => 'Descale coffee maker and kettle',
        'instructions' => 'More often on hard water.',
        'recur_kind'   => 'interval',
        'interval'     => array(6, 'month'),
        'location'     => 'Kitchen',
        'category'     => 'Appliances',
    ),
);
