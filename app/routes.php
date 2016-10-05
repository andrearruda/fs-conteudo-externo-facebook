<?php
// Routes

$app->get('/', function(){
    return $this->response->withRedirect($this->router->pathFor('facebook.login'));
});


$app->group('/facebook', function (){
    $this->get('/login', 'App\Action\FacebookAction:login')->setName('facebook.login');
    $this->get('/callback', 'App\Action\FacebookAction:callback')->setName('facebook.callback');
    $this->group('/info', function (){
        $this->get('/access-token', 'App\Action\FacebookAction:infoAccessToken')->setName('facebook.info.access-token');
    });
    $this->get('/posts/{user-id}[/{amount}]', 'App\Action\FacebookAction:posts')->setName('facebook.posts');
});