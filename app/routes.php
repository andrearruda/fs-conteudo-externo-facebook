<?php
// Routes

/*$app->get('/', function(){
    return $this->response->withRedirect($this->router->pathFor('ephemeris.yearly'));
});*/

$app->get('/{username}[/{amount}]', App\Action\FacebookAction::class)->setName('facebook');
