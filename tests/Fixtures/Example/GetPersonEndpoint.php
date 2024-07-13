<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Fixtures\Example;

class GetPersonEndpoint
{
    /**
     * @return Response<Person>
     */
    public function __invoke(): Response
    {
        return new Response(new Person('Joe', 170));
    }
}
