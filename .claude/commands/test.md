---
description: Run the pipelinq unit tests (PHPUnit and Vitest)
---

Run both unit suites from the repository root:

```bash
composer test:unit     # PHPUnit, tests/Unit (phpunit.xml)
npm run test:unit      # Vitest
```

To run one PHP test file: `./vendor/bin/phpunit tests/Unit/<path>Test.php`.
To run one Vitest file: `npx vitest run <path>`.

$ARGUMENTS
