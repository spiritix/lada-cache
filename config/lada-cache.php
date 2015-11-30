<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache granularity
    |--------------------------------------------------------------------------
    |
    | If you experience any issues while using the cache, try to set this value
    | to false. This will tell the cache to use a lower granularity and not
    | consider the row primary keys when creating the tags for a database query.
    | Since this will dramatically decrease the efficiency of the cache, it is
    | not recommended to do so in production environment.
    |
    */
    'consider-rows' => true,

];