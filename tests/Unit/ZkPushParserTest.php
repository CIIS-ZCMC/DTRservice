<?php

use App\Services\ZkPushParser;

test('ZkPushParser parses tab-separated key-values correctly', function () {
    $raw = "PIN=1001\tName=Juan Dela Cruz\tPri=0\tPasswd=\tCard=98765\tGrp=1\r\nPIN=1002\tName=Maria Santos\tPri=0\tPasswd=1234\tCard=11223\tGrp=1";
    
    $parsed = ZkPushParser::parseKeyValues($raw);

    expect($parsed)->toHaveCount(2);
    expect($parsed[0]['PIN'])->toBe('1001');
    expect($parsed[0]['Name'])->toBe('Juan Dela Cruz');
    expect($parsed[0]['Card'])->toBe('98765');
    expect($parsed[1]['PIN'])->toBe('1002');
    expect($parsed[1]['Name'])->toBe('Maria Santos');
});

test('ZkPushParser parses biometric template records correctly', function () {
    $raw = "PIN=1001\tFingerID=0\tSize=568\tValid=1\tTemplate=abc123xyz";
    
    $parsed = ZkPushParser::parseKeyValues($raw);

    expect($parsed)->toHaveCount(1);
    expect($parsed[0]['PIN'])->toBe('1001');
    expect($parsed[0]['FingerID'])->toBe('0');
    expect($parsed[0]['Template'])->toBe('abc123xyz');
});

test('ZkPushParser parses command execution ACK query string lines correctly', function () {
    $raw = "ID=101&Return=0&CMD=DATA USER\r\nID=102&Return=-1&CMD=DATA UPDATE";
    
    $parsed = ZkPushParser::parseQueryStringLines($raw);

    expect($parsed)->toHaveCount(2);
    expect($parsed[0]['ID'])->toBe('101');
    expect($parsed[0]['Return'])->toBe('0');
    expect($parsed[1]['ID'])->toBe('102');
    expect($parsed[1]['Return'])->toBe('-1');
});
