<?php

namespace hexa_package_wordpress_seo\Enums;

enum SeoScopeType: string
{
    case ALL_SERVERS = 'all_servers';
    case SERVER = 'server';
    case INSTALL = 'install';
    case PAGE = 'page';
}
