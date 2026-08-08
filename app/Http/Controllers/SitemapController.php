<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ChannelResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One sitemap per resolved storefront.
 *
 * *Which* products it lists is settled elsewhere — the store scope narrows
 * `Product` and `ProductCategory` to the resolved store, and an unresolved host
 * never reaches here. What is left is the two things scoping does not answer:
 * which hostname the URLs are written on, and how many of them there are.
 */
class SitemapController extends Controller
{
    /**
     * The sitemap protocol's ceiling: 50,000 URLs per file.
     *
     * A crawler is entitled to ignore a file that exceeds it, so an unbounded
     * `Product::all()` does not merely render slowly at 60,000 products — it
     * publishes a sitemap that need not be read at all. Overridable because the
     * limit is somebody else's number and a deployment may want to sit further
     * under it.
     */
    private const MAX_URLS = 50000;

    public function index(Request $request): Response
    {
        $limit = max(1, (int) config('sitemap.max_urls', self::MAX_URLS));
        $root = $this->canonicalRoot($request);

        // The home page is one URL, and the budget is spent in listing order:
        // categories are few and bounded by the merchant's own taxonomy,
        // products are not.
        $urls = [['loc' => $root.'/']];

        $categories = ProductCategory::query()
            ->select(['id', 'slug'])
            ->orderBy('id')
            ->limit($limit - count($urls))
            ->get();

        foreach ($categories as $category) {
            $urls[] = ['loc' => $root.'/products?category='.urlencode($category->slug)];
        }

        $products = Product::query()
            ->select(['id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->limit(max(0, $limit - count($urls)))
            ->get();

        foreach ($products as $product) {
            $urls[] = [
                'loc' => $root.route('products.show', $product, absolute: false),
                'lastmod' => $product->updated_at?->toAtomString(),
            ];
        }

        return response()
            ->view('sitemap.index', ['urls' => $urls])
            ->header('Content-Type', 'text/xml');
    }

    /**
     * The hostname the URLs are written on, which is not necessarily the one
     * the request arrived on.
     *
     * A storefront answers on several hostnames from day one — the apex, `www`,
     * a custom merchant domain, a platform subdomain — and `route()` builds from
     * whichever the crawler used. Two hostnames then publish two sitemaps naming
     * the same pages by different absolute URLs, which is duplicate content
     * announced to the crawler in the one file whose whole job is telling it
     * what to index. The primary domain flag exists for exactly this.
     *
     * The scheme stays the request's: a deployment behind TLS termination
     * reports it through `TrustProxies`, and hard-coding one here would publish
     * `http` URLs from an `https` storefront.
     */
    private function canonicalRoot(Request $request): string
    {
        $host = ChannelResolver::current()?->primaryDomain()?->host;

        return $host === null
            ? rtrim($request->getSchemeAndHttpHost(), '/')
            : $request->getScheme().'://'.$host;
    }
}
