<?php

use App\Support\BotAgents;

it('treats a missing or blank agent as a bot', function (?string $agent) {
    // Given no usable user agent
    // When it is checked
    // Then it is a bot
    expect(BotAgents::isBot($agent))->toBeTrue();
})->with([[null], [''], ['   '], ["\t\n"]]);

it('recognises crawlers, tools and previews', function (string $agent) {
    // Given a denylisted agent
    // When it is checked
    // Then it is a bot
    expect(BotAgents::isBot($agent))->toBeTrue();
})->with([
    'Googlebot/2.1', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'okhttp/4.12.0', 'axios/1.6.0', 'node-fetch/1.0', 'Go-http-client/2.0', 'libwww-perl/6.0', 'Scrapy/2.11', 'Chrome-Lighthouse', 'Pingdom.com_bot_version_1.4', 'WhatsApp/2.23', 'TelegramBot (like TwitterBot)', 'AhrefsBot/7.0', 'SomeBot 1.0', 'bot', 'my bot/1.0', 'HeadlessChrome/120', 'curl/8.0', 'python-requests/2.31',
    'Slackbot-LinkExpanding', 'Twitterbot', 'Mozilla/5.0 (X11) Chrome/120 Safari/537.36 Preview', 'Wget/1.21',
    'facebookexternalhit/1.1', 'Mozilla/5.0 (compatible; Yahoo! Slurp)', 'UptimeMonitor/1.0',
]);

it('lets real browsers and the mobile app through', function (string $agent) {
    // Given an ordinary agent
    // When it is checked
    // Then it is not a bot
    expect(BotAgents::isBot($agent))->toBeFalse();
})->with([
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    'Launchpad/1.0 (iOS 18)',
    'Mozilla/5.0 (Linux; Android 10; CUBOT_X30) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 9; CUBOT X19) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
]);

it('matches case-insensitively', function () {
    // Given upper and lower case spellings
    // When they are checked
    // Then both are bots
    expect(BotAgents::isBot('MONITOR'))->toBeTrue()->and(BotAgents::isBot('GoogleBot'))->toBeTrue()->and(BotAgents::isBot('googlebot'))->toBeTrue()->and(BotAgents::isBot('GOOGLEBOT'))->toBeFalse();
});

it('follows the configured denylist', function () {
    // Given an agent that is fine by default
    expect(BotAgents::isBot('Launchpad/1.0 (iOS 18)'))->toBeFalse();

    // When the denylist gains its name
    config(['launchpad.views.bot_agents' => ['launchpad/']]);

    // Then it is a bot, and the old entries no longer apply
    expect(BotAgents::isBot('Launchpad/1.0 (iOS 18)'))->toBeTrue()->and(BotAgents::isBot('Googlebot/2.1'))->toBeFalse();
});
