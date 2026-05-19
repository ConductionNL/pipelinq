<?php

namespace Fixtures;

/**
 * A sample class without any spec tag.
 */
class ClassWithoutSpec
{


    /**
     * A public method without a spec tag.
     */
    public function doSomething()
    {
        return 'nope';

    }//end doSomething()


    /**
     * A private method — must not be flagged.
     */
    private function internalHelper()
    {
        return 'nope';

    }//end internalHelper()


}//end class
