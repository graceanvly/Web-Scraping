<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a bulk scrape run is superseded or the client disconnected mid-fetch. */
class ScrapeAbortedException extends RuntimeException
{
}
