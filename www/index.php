<?php

require_once('../mustache-view.php');
$autoloader = require_once '../vendor/autoload.php';

use \Goutte\Client;

$container_options = [
    'settings' => [
        'displayErrorDetails' => true
    ],
    'view' => function ($c) {
        $view = new \Slim\Views\Mustache('../templates');
        return $view;
    }
];
$container = new \Slim\Container($container_options);

//$container['errorHandler'] = function ($container) {
//    return function ($request, $response, $exception) use ($container) {
//        return $container['response']->withStatus(500)
//            ->withHeader('Content-Type', 'text/html')
//            ->write('Something went wrong!');
//    };
//};

$app = new \Slim\App($container);

/**
 * Atom Heart Crawler
 * @todo Turn into class
 * @todo Add some type of logging for failed scrapings
 */
$app->get('/atomheart', function ($request, $response, $args) {

    $links = [];
    $nodes = [];
    $client = new Client();

    $client
        ->request('GET', 'http://www.atomheart.ca/wordpress/category/billets-tickets/')
        ->filter('#content-archive > .category-billets-tickets > .post-title > a')
        ->each(function ($node) use (&$links) {
            $links[] = $node->attr('href');
        });

    foreach ($links as $link) {
        $client
            ->request('GET', $link)
            ->filter('#content > .post > .post-entry > p')
            ->each(function ($node) use (&$nodes) {

                // Okay, let's try to sort that
                $split_content = preg_split('/<br[^>]*>/i', $node->html());

                // Majority of shows are 4 lines. If less or more, fuck that, not dealing with you
                // Or worse yet, format has changed. Abandon ship
                if (count($split_content) === 4) {

                    // Splitting line 1 (bilingual date strings)
                    $date_array = preg_split('/\|/', $split_content[0]);
                    // We'll use the english one for simplicity's sake (normally at end)
                    $date = end($date_array);
                    // Sometime months will be in the wrong language anyways
                    $date = str_replace(
                    [
                        'janvier',
                        'février',
                        'mars',
                        'avril',
                        'mai',
                        'juin',
                        'juillet',
                        'août',
                        'septembre',
                        'octobre',
                        'novembre',
                        'décembre'
                    ],
                    [
                        'january',
                        'february',
                        'march',
                        'april',
                        'may',
                        'june',
                        'july',
                        'august',
                        'september',
                        'october',
                        'november',
                        'december'
                    ], $date);

                    // Splitting line 2 (artists)
                    $artists_array = preg_split('/\|/', $split_content[1]);
                    $artists = [];

                    foreach ($artists_array as $artist) {
                        $artists[] = [
                            'name' => trim($artist)
                        ];
                    }

                    // Splitting line 3 (location location location)
                    // For now, I don't give a shit about adresses
                    $location_array = preg_split('/(:)/', $split_content[2]);
                    $location = trim(current($location_array));

                    // Splitting line 4 (price, time)
                    // Usually, index [1] is a useless string
                    // Just in case it's not there, we'll get around it by fetching both extremities
                    $details_array = preg_split('/\|/', $split_content[3]);
                    $price = preg_replace('/[^\d,\.]/', '', trim(current($details_array)));
                    $price = preg_replace('/,(\d{2})$/', '.$1', $price);
                    $time = trim(str_replace(['Doors','Portes','@'], '', end($details_array)));

                    // Generating a DateTime object using the combination of $time and date string
                    $datetime = new \DateTime($date . ' ' . $time);

                    $nodes[] = [
                        'timestamp' => $datetime->getTimestamp(),
                        'date' => $datetime->format('d F Y'),
                        'time' => $datetime->format('H:i'),
                        'artists_string' => $split_content[1],
                        'artists' => $artists,
                        'location' => $location,
                        'price' => $price
                    ];
                }
            });
    }

    $args = [
        'nodes' => $nodes
    ];


    return $this->view->render($response, 'index', $args);
});

/**
 * BSTB Crawler
 * @todo Turn into class
 * @todo Add some type of logging for failed scrapings
 */
$app->get('/bstb', function ($request, $response, $args) {
    $links = [
        'http://blueskiesturnblack.com/shows?page=0',
        'http://blueskiesturnblack.com/shows?page=1'
    ];
    $nodes = [];
    $client = new Client();

    foreach ($links as $link) {
        $client
            ->request('GET', $link)
            ->filter('.view-shows > .view-content > .views-row')
            ->each(function ($node) use (&$nodes) {
                // This node contains date & time and artists, nothing else interests us
                // Date is relatively straight forward stuff
                $date = trim($node->filter('.views-field-nothing .date-display-single')->text());
                // For some reason, they are displaying times in 24h format WITH 12h periods
                // Lazy removal, could break things
                $time = str_replace(['am','pm'], '', trim($node->filter('.views-field-nothing .showtime')->text()));
                // Generating a DateTime object using the combination of $time and $date string
                $datetime = new \DateTime($date . ', ' . $time);

                // Fetching artists
                $artists = [];
                // 4 artists or less are output, but sometimes are simply empty markup
                $node->filter('.views-field-nothing [class^="act"] a')->each(function ($artist_node) use (&$artists) {
                    $artists[] = [
                        'name' => trim($artist_node->text())
                    ];
                });

                // This node contains location and pricing
                // Location is tricky since there is nearly always a Google Maps link somewhere in there
                $location_array = preg_split('/<br[^>]*>/i', $node->filter('.views-field-nothing-1 .location')->html());
                $location = trim(current($location_array));

                // Fetching price
                // Opinionated decision to treat door price as official price
                // Gotta skirt around that annoying <strong> around the label. Goutte and a nice regex have got us covered
                $price = preg_replace('/[^\d,\.]/', '', trim($node->filter('.views-field-nothing-1 .doorPrice')->text()));
                $price = preg_replace('/,(\d{2})$/', '.$1', $price);

                $nodes[] = [
                    'timestamp' => $datetime->getTimestamp(),
                    'date' => $datetime->format('d F Y'),
                    'time' => $datetime->format('H:i'),
                    'artists' => $artists,
                    'location' => $location,
                    'price' => $price
                ];
            });
    }

    $args = [
        'nodes' => $nodes
    ];

    return $this->view->render($response, 'index', $args);
});

/**
 * Corona Theatre
 * @todo Turn into class
 * @todo Add some type of logging for failed scrapings
 */
$app->get('/corona', function ($request, $response, $args) {

    $links = [
        'http://www.theatrecoronavirginmobile.com/calendar/'
    ];
    $nodes = [];
    $client = new Client();

    foreach ($links as $link) {
        $client
            ->request('GET', $link)
            ->filter('.event_list > .shortpost')
            ->each(function ($node) use (&$nodes) {
                // `shortpost_date` node contains date and nothing else
                //
                $date_content = trim($node->filter('.shortpost_date')->html());
                // It's in an HTML comment? regex shall do
                preg_match('#\d{10,11}#', $date_content, $date_timestamp);
                $date_object = new \DateTime();
                $date_object->setTimestamp(current($date_timestamp));
                // Remember to ignore the time, it's not good within the timestamp
                $date = $date_object->format('d F Y');

                // Main artist is easy enough to fetch
                $artists = [
                    [
                        'name' => $node->filter('.shortpost_details h1')->text()
                    ]
                ];

                // Supporting artists are a little harder. They are merged with prices and times
                $details = [];
                // Split those details by node
                $node->filter('.shortpost_details .shortpost_with')->each(function ($detail_node) use (&$details) {
                    $details[] = trim(str_replace('Avec :','',$detail_node->text()));
                });
                // The node order seems to be respected throughout. However, if no supportings, there are 3 `.shortpost_with` nodes instead of 4
                // Harcoding that count, living with the fear of failures!
                if (count($details) === 4) {
                    // Splitting artists by comma. Risky.
                    $artists_array = preg_split('/\,/', array_shift($details));

                    foreach ($artists_array as $artist) {
                        $artists[] = [
                            'name' => trim($artist)
                        ];
                    }
                }

                // Index 1 contains show time (24h clock), contains pesky `h` character
                $time = str_replace(['h','H'], '', $details[1]);
                // Generating a DateTime object using the combination of $time and $date string
                $datetime = new \DateTime($date . ', ' . $time);

                // Index 2 contains pricing. We'll need to do a bit of comparing.
                // A majority of the time, they output both prices in the string.
                // We'll strip down the string -
                $price_string = str_replace(['Prix : À L\'avance', 'À L\'avance', 'Jour du spectacle', 'À l\'avance', 'À L’avance', ':'], '', $details[2]);
                $price_string = str_replace(',', '.', $price_string);
                // Match any strings that look like prices -
                preg_match_all('/\d+(?:\.\d{1,2})?/', $price_string, $price_array);
                // And use end index since it's logically the larger number. More potential failure!
                $price = end(end($price_array));

                $location = 'Corona Theatre';

                $nodes[] = [
                    'timestamp' => $datetime->getTimestamp(),
                    'date' => $datetime->format('d F Y'),
                    'time' => $datetime->format('H:i'),
                    'artists' => $artists,
                    'location' => $location,
                    'price' => $price
                ];

            });
    }

    $args = [
        'nodes' => $nodes
    ];

    return $this->view->render($response, 'index', $args);
});

/**
 * Greenland Productions
 */
$app->get('/greenland', function ($request, $response, $args) {

    $links = [];
    $nodes = [];
    $client = new Client();

    $client
        ->request('GET', 'http://www.greenland.ca/event')
        ->filter('#main_content > .listing > a')
        ->each(function ($node) use (&$links) {
            $links[] = $node->attr('href');
        });

    // Using this to manage the fact that the year is not listed in the show date
    $static_datetime = new \DateTime('today');

    foreach ($links as $link) {
        //var_dump($link);
        $node = $client
            ->request('GET', $link)
            ->filter('.event_details');

        // Main artist might sometimes be TWO artists
        $artists = [];
        $node
            ->filter('h2')
            ->each(function ($artist_node) use (&$artists) {
                $artists[] = [
                    'name' => trim($artist_node->text())
                ];
            });

        // And then we have supporting artists
        $node
            ->filter('.openers span')
            ->each(function ($artist_node) use (&$artists) {
                $name = trim($artist_node->text());
                // Yeahhhhh, I'm just gonna try an early "GUEST ARTIST?!?! OMG" filter prototype
                if (!preg_match('/'.implode('|', ['invité','guest','gue5t']).'/', $name)) {
                    $artists[] = [
                        'name' => $name
                    ];
                }
            });

        // Location is pretty simple to extract. For some reason, they list the town underneath.
        // Other town possible?! Who knows. Wait till it breaks.
        // Split on line break, use first index
        $location_array = preg_split('/<br[^>]*>/i', $node->filter('.venue a')->html());
        $location = current($location_array);

        // Easy enough date to extract
        $date = trim($node->filter('.date')->text());
        // Time seems to always follow the same format. Taking advantage of that.
        // Doors: 7:30 PM // Show: 8:30 PM
        $time_array = explode('//', $node->filter('.doors')->text());
        $time = trim(str_replace(['Show:','Doors:'], '', end($time_array)));

        // Generating a DateTime object using the combination of $time and $date string
        // However we're missing a year, so we'll use today's year
        $datetime = new \DateTime($date . ' ' . $static_datetime->format('Y') . ', ' . $time);
        // If $datetime is smaller than $static_datetime, it means we've changed year!
        if ($datetime < $static_datetime) {
            // Set $datetime's year as next year
            $datetime->modify('+1 year');
            // OVerwrite $static_datetime as $datetime
            $static_datetime = $datetime;
        }

        // Price is buried deep, with no classes
        // Always after `.doors` though!
        $price_string = trim($node->filter('.doors')->nextAll()->eq(0)->text());

        //$price_string = str_replace(',', '.', $price_string);
        // Match any strings that look like prices
        preg_match_all('/\d+(?:\.\d{1,2})?/', $price_string, $price_array);
        // And use end index since it's logically the larger number. More potential failure!
        $price = end(end($price_array));

        $nodes[] = [
            'timestamp' => $datetime->getTimestamp(),
            'date' => $datetime->format('d F Y'),
            'time' => $datetime->format('H:i'),
            'artists' => $artists,
            'location' => $location,
            'price' => $price
        ];
    }

    $args = [
        'nodes' => $nodes
    ];

    return $this->view->render($response, 'index', $args);
});

/**
 * Tixza
 */
$app->get('/tixza', function ($request, $response, $args) {

    $links = [];
    $nodes = [];
    $client = new Client();

    $client
        ->request('GET', 'http://www.en.tixza.com/event')
        ->filter('#content > .listing > a')
        ->each(function ($node) use (&$links) {
            $links[] = $node->attr('href');
        });

    // Using this to manage the fact that the year is not listed in the show date
    $static_datetime = new \DateTime('today');

    foreach ($links as $link) {
        //var_dump($link);
        $node = $client
            ->request('GET', $link)
            ->filter('.event_details');

        // Artists seem to be stores in first h2 and h3
        $artists = [];
        $node
            ->filter('h2, h3')
            ->each(function ($artist_node) use (&$artists) {
                $artists[] = [
                    'name' => trim($artist_node->text())
                ];
            });

        // Rest of the details are pretty sketchy to access, all of em in unmarked <p>
        // Make sure we get them starting after the last possible artist
        // Their order stays constant though!
        $details = $node->filter('h2, h3')->last()->nextAll();

        // Location is first
        $location = trim($details->eq(0)->text());

        // Date and time are second and third
        // Easy enough date to extract
        $date = trim($details->eq(1)->text());
        // Time seems to always follow the same format. Taking advantage of that.
        // Show: 21h30
        $time = trim(str_replace(['Spectacle:','Show:'], '', $details->eq(3)->text()));

        // Generating a DateTime object using the combination of $time and $date string
        // However we're missing a year, so we'll use today's year
        $datetime = new \DateTime($date . ' ' . $static_datetime->format('Y') . ', ' . $time);
        // If $datetime is smaller than $static_datetime, it means we've changed year!
        if ($datetime < $static_datetime) {
            // Set $datetime's year as next year
            $datetime->modify('+1 year');
            // OVerwrite $static_datetime as $datetime
            $static_datetime = $datetime;
        }

        // Price is fourth
        $price_string = trim($details->eq(4)->text());
        // Match any strings that look like prices
        preg_match_all('/\d+(?:\.\d{1,2})?/', $price_string, $price_array);
        // And use end index since it's logically the larger number. More potential failure!
        $price = end(end($price_array));

        $nodes[] = [
            'timestamp' => $datetime->getTimestamp(),
            'date' => $datetime->format('d F Y'),
            'time' => $datetime->format('H:i'),
            'artists' => $artists,
            'location' => $location,
            'price' => $price
        ];
    }

    $args = [
        'nodes' => $nodes
    ];

    return $this->view->render($response, 'index', $args);
});

$app->run();