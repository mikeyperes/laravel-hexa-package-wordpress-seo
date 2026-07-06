<?php

namespace hexa_package_wordpress_seo\Enums;

enum SeoFeature: string
{
    case SEO_TITLE = 'seo_title';
    case SEO_DESCRIPTION = 'seo_description';
    case FEATURED_IMAGE = 'featured_image';
    case SEO_SCORE = 'seo_score';
    case FOCUS_KEYWORD = 'focus_keyword';
    case INTERNAL_LINKS = 'internal_links';
}
