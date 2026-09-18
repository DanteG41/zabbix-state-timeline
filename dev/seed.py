#!/usr/bin/env python3
"""
Seed the development environment (dev/docker-compose.yml) with test data:

- registers and enables the module;
- creates hosts with trapper items "xray.observatory.alive[...]":
  "State timeline A": 60 unsigned items with value map (0 - Down, 1 - Up): mostly up with random down periods,
  one flapping item, one item that never changes, one item with rare values (every 20-35 minutes, 12:00 = 1,
  12:01 = 1, 12:02 = 1, 12:37 = 0 like), one item without data;
  "State timeline B": 5 float items (0.0 / 1.0) and 1 float item 0..100 (for the threshold setting);
  "State timeline C": 50 unsigned items, so that more than 100 items match "xray.observatory.alive[*]";
- inserts 8 hours of history (30 s interval) and 14 days of trends directly into the database;
- creates dashboard "State timeline" with several widgets and the standard Graph widget for comparison, and a
  "Styles" page with a widget of each style.

Usage: python3 dev/seed.py [http://localhost:8090]
"""

import json
import random
import subprocess
import sys
import time
import urllib.request

URL = (sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8090') + '/api_jsonrpc.php'
POSTGRES_CONTAINER = 'zabbix-statetimeline-postgres-1'
DASHBOARD = 'State timeline'
HISTORY_PERIOD = 8 * 3600
TRENDS_DAYS = 14
DELAY = 30


def api(method, params, auth=None):
    request = urllib.request.Request(URL, json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1})
        .encode(), {'Content-Type': 'application/json-rpc', **({'Authorization': 'Bearer ' + auth} if auth else {})})
    response = json.load(urllib.request.urlopen(request))

    if 'error' in response:
        raise RuntimeError(f'{method}: {response["error"]}')

    return response['result']


def psql(sql):
    subprocess.run(['docker', 'exec', '-i', POSTGRES_CONTAINER, 'psql', '-q', '-U', 'zabbix', '-d', 'zabbix'],
        input=sql.encode(), check=True)


def insert(table, columns, rows):
    for i in range(0, len(rows), 20000):
        psql(f'INSERT INTO {table} ({columns}) VALUES {",".join(rows[i:i + 20000])};')


def up_down(now, rng, down_rate, down_max):
    """Samples of a mostly "up" item with random "down" periods."""
    samples = []
    down_until = 0

    for clock in range(now - HISTORY_PERIOD, now, DELAY):
        if clock >= down_until and rng.random() < down_rate:
            down_until = clock + rng.randint(1, down_max) * 60

        samples.append((clock, 0 if clock < down_until else 1))

    return samples


def main():
    auth = api('user.login', {'username': 'Admin', 'password': 'zabbix'})

    modules = api('module.get', {'output': ['moduleid', 'status'], 'filter': {'id': 'statetimeline'}}, auth)

    if not modules:
        api('module.create', [{'id': 'statetimeline', 'relative_path': 'modules/statetimeline', 'status': 1}], auth)
    elif modules[0]['status'] != '1':
        api('module.update', [{'moduleid': modules[0]['moduleid'], 'status': 1}], auth)

    for dashboard in api('dashboard.get', {'output': ['dashboardid'], 'filter': {'name': DASHBOARD}}, auth):
        api('dashboard.delete', [dashboard['dashboardid']], auth)

    hosts = {'A': 'State timeline A', 'B': 'State timeline B', 'C': 'State timeline C'}

    for host in api('host.get', {'output': ['hostid'], 'filter': {'host': list(hosts.values())}}, auth):
        api('host.delete', [host['hostid']], auth)

    groupid = api('hostgroup.get', {'output': ['groupid'], 'filter': {'name': 'Linux servers'}}, auth)[0]['groupid']
    hostids = {code: api('host.create', {'host': name, 'groups': [{'groupid': groupid}]}, auth)['hostids'][0]
        for code, name in hosts.items()}

    valuemapid = api('valuemap.create', {'hostid': hostids['A'], 'name': 'Alive', 'mappings': [
        {'value': '0', 'newvalue': 'Down'}, {'value': '1', 'newvalue': 'Up'}
    ]}, auth)['valuemapids'][0]

    now = int(time.time())
    rng = random.Random(1)

    # (host, name, key, value type, samples)
    specs = []

    for i in range(1, 61):
        if i == 7:
            samples = [(clock, rng.randint(0, 1) if rng.random() < 0.3 else 1)
                for clock in range(now - HISTORY_PERIOD, now, DELAY)]
        elif i == 12:
            samples = [(clock, 1) for clock in range(now - HISTORY_PERIOD, now, DELAY)]
        elif i == 17:
            samples = []
            clock, value = now - HISTORY_PERIOD, 1

            while clock < now:
                samples.append((clock, value))
                clock += rng.randint(20, 35) * 60
                value = 1 - value if rng.random() < 0.5 else value
        elif i == 23:
            samples = []
        else:
            samples = up_down(now, rng, 0.004, 30)

        specs.append(('A', f'Observatory alive {i:02d}', f'xray.observatory.alive[{i}]', 3, samples))

    for i in range(1, 6):
        samples = [(clock, f'{value}.0') for clock, value in up_down(now, rng, 0.003, 20)]
        specs.append(('B', f'Outbound {i} alive', f'xray.observatory.alive[b{i}]', 0, samples))

    specs.append(('B', 'Outbound quality', 'xray.observatory.alive[quality]', 0,
        [(clock, round(rng.uniform(0, 100), 2)) for clock in range(now - HISTORY_PERIOD, now, DELAY)]))

    for i in range(1, 51):
        specs.append(('C', f'Balancer alive {i:02d}', f'xray.observatory.alive[c{i}]', 3,
            up_down(now, rng, 0.002, 15)))

    itemids = api('item.create', [{
        'hostid': hostids[host], 'name': name, 'key_': key, 'type': 2, 'value_type': value_type,
        'history': '31d', 'trends': '365d', **({'valuemapid': valuemapid} if host == 'A' else {})
    } for host, name, key, value_type, _ in specs], auth)['itemids']

    history = {0: [], 3: []}
    trends = {0: [], 3: []}

    for itemid, (host, name, key, value_type, samples) in zip(itemids, specs):
        for clock, value in samples:
            history[value_type].append(f'({itemid},{clock},{value},{rng.randint(0, 999999999)})')

        if not samples:
            continue

        for clock in range((now - TRENDS_DAYS * 86400) // 3600 * 3600, now // 3600 * 3600, 3600):
            down = rng.random() < 0.05
            low, high = (0, 1) if down else (1, 1)
            trends[value_type].append(f'({itemid},{clock},120,{low},{0.9 if down else 1},{high})')

    for value_type, table in ((0, 'history'), (3, 'history_uint')):
        insert(table, 'itemid,clock,value,ns', history[value_type])

    for value_type, table in ((0, 'trends'), (3, 'trends_uint')):
        insert(table, 'itemid,clock,num,value_min,value_avg,value_max', trends[value_type])

    def fields(pattern, extra=()):
        return [{'type': 1, 'name': 'items.0', 'value': pattern}, {'type': 0, 'name': 'item_match', 'value': 2}] \
            + list(extra)

    dashboard = api('dashboard.create', {
        'name': DASHBOARD,
        'pages': [{
            'widgets': [
                {'type': 'statetimeline', 'name': 'All observatories (more than 100 items)', 'x': 0, 'y': 0,
                    'width': 36, 'height': 8, 'fields': fields('xray.observatory.alive[*]')},
                {'type': 'statetimeline', 'name': 'Host A (60 items)', 'x': 36, 'y': 0, 'width': 36, 'height': 8,
                    'fields': fields('*', [{'type': 3, 'name': 'hostids.0', 'value': hostids['A']}])},
                {'type': 'statetimeline', 'name': 'Host B, 7 days', 'x': 0, 'y': 8, 'width': 24, 'height': 4,
                    'fields': fields('xray.observatory.alive[b*]', [
                        {'type': 1, 'name': 'time_period.from', 'value': 'now-7d'},
                        {'type': 1, 'name': 'time_period.to', 'value': 'now'}
                    ])},
                {'type': 'statetimeline', 'name': 'Quality >= 50, custom colors', 'x': 24, 'y': 8, 'width': 16,
                    'height': 4, 'fields': fields('xray.observatory.alive[quality]', [
                        {'type': 1, 'name': 'threshold', 'value': '50'},
                        {'type': 1, 'name': 'state_1_label', 'value': 'Good'},
                        {'type': 1, 'name': 'state_0_label', 'value': 'Poor'},
                        {'type': 1, 'name': 'state_1_color', 'value': '4FC3F7'},
                        {'type': 1, 'name': 'state_0_color', 'value': 'FFB74D'},
                        {'type': 1, 'name': 'time_period.from', 'value': 'now-30m'},
                        {'type': 1, 'name': 'time_period.to', 'value': 'now'}
                    ])},
                {'type': 'statetimeline', 'name': 'Narrow', 'x': 40, 'y': 8, 'width': 8, 'height': 4,
                    'fields': fields('xray.observatory.alive[*]')},
                {'type': 'svggraph', 'name': 'Graph (host B)', 'x': 48, 'y': 8, 'width': 24, 'height': 4,
                    'fields': [
                        {'type': 1, 'name': 'ds.0.hosts.0', 'value': hosts['B']},
                        {'type': 1, 'name': 'ds.0.items.0', 'value': '*'},
                        {'type': 1, 'name': 'ds.0.color', 'value': 'FF465C'},
                        {'type': 0, 'name': 'ds.0.type', 'value': 2}
                    ]}
            ]
        }]
    }, auth)

    styles = ['Segments', 'Flat', 'Capsules', 'Uptime bars', 'Glossy', 'Track and incidents', 'Hatched incidents']
    styles_page = [
        {'type': 'statetimeline', 'name': f'Style: {name}', 'x': (i % 3) * 24, 'y': (i // 3) * 6, 'width': 24,
            'height': 6, 'fields': fields('*', [
                {'type': 3, 'name': 'hostids.0', 'value': hostids['A']},
                {'type': 0, 'name': 'style', 'value': i}
            ])}
        for i, name in enumerate(styles)
    ]

    api('dashboard.update', {'dashboardid': dashboard['dashboardids'][0], 'pages': [
        {'dashboard_pageid': api('dashboard.get', {'output': [], 'selectPages': ['dashboard_pageid'],
            'dashboardids': dashboard['dashboardids']}, auth)[0]['pages'][0]['dashboard_pageid']},
        {'name': 'Styles', 'widgets': styles_page}
    ]}, auth)

    print(json.dumps({'hostids': hostids, 'items': len(itemids), 'dashboardid': dashboard['dashboardids'][0]}))


if __name__ == '__main__':
    main()
