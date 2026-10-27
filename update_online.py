import json
import socket
import struct
from pathlib import Path

PATH = Path(__file__).with_name('servers-base.json')
OUTPUT = Path(__file__).with_name('online.json')

def query(ip, port):
    octets = bytes(map(int, ip.split('.')))
    request = b'SAMP' + octets + struct.pack('<H', port) + b'i'
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.settimeout(4)
        sock.sendto(request, (ip, port))
        data, _ = sock.recvfrom(4096)
    if not data.startswith(request) or len(data) < 16:
        raise ValueError('Invalid SA-MP response')
    players, maximum = struct.unpack_from('<HH', data, 12)
    if maximum < players:
        raise ValueError('Invalid player counts')
    return players, maximum

servers = json.loads(PATH.read_text(encoding='utf-8'))
previous = {}
if OUTPUT.exists():
    try:
        previous = json.loads(OUTPUT.read_text(encoding='utf-8'))
    except (ValueError, OSError):
        pass

result = {}
for server in servers:
    key = str(server['id'])
    if key != '1':
        continue
    try:
        players, maximum = query(server['ip'], int(server['port']))
        result[key] = {'online': str(players), 'maxonline': str(maximum)}
        print(f'{key}: {players}/{maximum}')
    except (OSError, ValueError) as exc:
        print(f'{key}: query failed: {exc}')
        if key in previous:
            result[key] = previous[key]

if not result:
    raise SystemExit('No valid online data')
OUTPUT.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
