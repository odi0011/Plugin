<?php

final class SitemapPingApi
{
    public static function status(array $arguments, array $context): array
    {
        return SitemapPingActions::status($arguments, $context);
    }

    public static function submit(array $arguments, array $context): array
    {
        return SitemapPingActions::requestSubmit($arguments, $context);
    }
}
