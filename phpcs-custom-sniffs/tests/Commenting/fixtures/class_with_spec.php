<?php

namespace Fixtures;

/**
 * A sample class with a spec tag.
 *
 * @spec openspec/changes/add-spec-sniff/tasks.md#task-1
 */
class ClassWithSpec
{


    /**
     * A public method with a spec tag.
     *
     * @spec openspec/changes/add-spec-sniff/tasks.md#task-2
     */
    public function doSomething()
    {
        return 'ok';

    }//end doSomething()


    /**
     * Magic method — must not be flagged even without spec tag.
     */
    public function __construct()
    {

    }//end __construct()


    /**
     * A protected method — must not be flagged.
     */
    protected function protectedHelper()
    {
        return 'ok';

    }//end protectedHelper()


    /**
     * Default visibility (public) without spec tag — must be flagged.
     */
    function defaultPublic()
    {
        return 'flagged';

    }//end defaultPublic()


}//end class
