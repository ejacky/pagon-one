# Intro

One file framework with [Pagon](https://github.com/hfcorriez/pagon)

# Usage

```
require 'pagon1.php';

$app = new \Pagon\App();
$app->get('/', function($req, $res) {
    $res->end('One is everything!');
});
$app->run();
```

More to see [Pagon Home Page](https://github.com/hfcorriez/pagon)