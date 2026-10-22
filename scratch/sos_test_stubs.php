<?php
namespace SosIntegration;
class ApiFailure extends \RuntimeException {public function __construct(public array $payload){parent::__construct($payload['error_code']??'API error');}}
function getDB(){return $GLOBALS['testDb'];}
function json_response($success,$data=[],$message=''){throw new ApiFailure($data);}
function push_notification(...$args){throw new \RuntimeException('Simulated notification outage');}
function log_activity(...$args){}
