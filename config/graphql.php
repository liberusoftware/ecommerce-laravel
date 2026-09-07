<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bounds on a single GraphQL request
    |--------------------------------------------------------------------------
    |
    | `/api/graphql` is public and anonymous: the catalogue is readable without
    | a token, so the cost of one request is the only thing standing between a
    | stranger and a worker. Each bound stops something the others do not.
    |
    | `throttle:api` is not one of these. It limits how many requests a caller
    | makes, not what one request costs, and one query is enough.
    |
    */

    // How deeply a query may nest. Bounds the shape.
    'max_depth' => 10,

    // Webonyx's own cost estimate for a query. Bounds the shape too, more
    // finely — but both are static, and neither knows what the database will do
    // with the query once it is judged acceptable.
    'max_complexity' => 200,

    /*
    | Wall clock, in seconds, for one request.
    |
    | Ten, because a storefront request that has not finished in ten seconds has
    | already failed the shopper — they are gone long before a browser or CDN
    | gives up — and the bound exists to stop a query holding a worker after the
    | person who asked for it has left. Lower it on a deployment that knows its
    | own queries are faster; there is no value in waiting longer than the
    | caller will.
    |
    | Checked between resolvers, so it ends a query that is still going. It
    | cannot interrupt a single statement already in flight — that is a
    | statement timeout on the database connection, and a different control.
    */
    'execution_timeout' => 10,

];
