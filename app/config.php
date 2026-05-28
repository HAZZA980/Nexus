<?php
return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'NexusCMS',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
  ],
  'app' => [
    'base_path' => '/phpProjects/NexusCMS',
    'env' => 'dev',
    'session_name' => 'nexuscms_session',
  ],
  'mail' => [
    'transport' => 'log', // change to 'mail' on servers with outbound mail configured
    'from_email' => 'noreply@nexuscms.local',
    'from_name' => 'NexusCMS',
    'subject_prefix' => '[NexusCMS]',
    'log_file' => __DIR__ . '/../storage/mail.log',
  ],
  'ai' => [
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
    'gemini_model' => getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash',
    'gemini_endpoint' => getenv('GEMINI_ENDPOINT') ?: 'https://generativelanguage.googleapis.com/v1beta',
  ],
];
