#!/usr/bin/env python3
"""
HelpTI — Scanner de Rede v4 (Windows + Linux)
Uso: python scanner_rede.py [rede1/cidr] [rede2/cidr] ...
Ex:  python scanner_rede.py 192.168.1.0/24 10.0.1.0/24
     python scanner_rede.py          # detecta rede automaticamente
"""

import subprocess, socket, csv, sys, os, re, json, ipaddress, platform
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

IS_WINDOWS = platform.system() == 'Windows'

# ── Fabricantes com prioridade absoluta ──────────────────────
FABRICANTE_PRIORIDADE = {
    'synology':    'Servidor NAS',
    'qnap':        'Servidor NAS',
    'netgear':     'Switch',
    'cisco':       'Switch',
    'routerboard': 'Roteador MikroTik',
    'ubiquiti':    'Access Point',
    'aruba':       'Access Point',
    'intelbras':   'Switch/AP Intelbras',
    '8devices':    'Roteador',
    'weintek':     'IHM/Painel',
    'siemens':     'Equipamento Médico',
    'sord':        'Terminal',
    'tecnomen':    'Equipamento Especial',
    'control id':  'Controle de Acesso',
    'control-id':  'Controle de Acesso',
    'microsoft':   'Servidor',
    'lantronix':   'Servidor',
    'fr. sauter':  'Equipamento Especial',
}

TIPO_POR_FABRICANTE = {
    'kyocera':    'Impressora',
    'xerox':      'Impressora',
    'zebra':      'Impressora Etiqueta',
    'bematech':   'Impressora',
    'brother':    'Impressora',
    'lexmark':    'Impressora',
    'dell':       'Desktop',
    'hp ':        'Desktop',
    'hewlett':    'Desktop',
    'lenovo':     'Notebook',
    'asrock':     'Desktop',
    'asustek':    'Desktop',
    'biostar':    'Desktop',
    'elitegroup': 'Desktop',
    'pegatron':   'Desktop',
    'fujitsu':    'Desktop',
    'lg electr':  'Desktop',
    'comtec':     'Desktop',
    'micro-star': 'Desktop',
    'giga-byte':  'Desktop',
    'gigabyte':   'Desktop',
    'intel corp': 'Desktop',
    'hon hai':    'Desktop',
    'cal-comp':   'Desktop',
    'super micro':'Servidor',
    'advantech':  'Servidor',
    'congatec':   'Servidor',
}

def classifica_tipo(fabricante: str, portas: list) -> str:
    fab = fabricante.lower()
    for chave, tipo in FABRICANTE_PRIORIDADE.items():
        if chave in fab:
            return tipo
    if 445 in portas and 135 in portas:
        return 'Desktop'
    if 3389 in portas:
        return 'Desktop'
    if 9100 in portas:
        return 'Impressora'
    for chave, tipo in TIPO_POR_FABRICANTE.items():
        if chave in fab:
            return tipo
    if 515 in portas or 631 in portas:
        return 'Impressora'
    return 'Computador'

# ── Detecção de redes locais ──────────────────────────────────
def detecta_todas_redes() -> list:
    redes = []
    ignorar_prefixos = ('127.', '169.254.', '172.17.', '172.18.', '172.19.', '172.2', '::')

    if IS_WINDOWS:
        try:
            out = subprocess.check_output(['ipconfig'], stderr=subprocess.DEVNULL).decode('cp850', errors='ignore')
            iface = 'eth0'
            for linha in out.splitlines():
                # Captura nome da interface
                if re.match(r'^[^\s]', linha) and 'adapter' in linha.lower():
                    iface = linha.strip().rstrip(':')
                m = re.search(r'IPv4.*?:\s*([\d\.]+)', linha)
                if not m:
                    m = re.search(r'Endere.*?IPv4.*?:\s*([\d\.]+)', linha)
                if m:
                    ip = m.group(1)
                    if any(ip.startswith(p) for p in ignorar_prefixos):
                        continue
                    # Máscara na próxima linha
                    mask_m = re.search(r'M[aá]scara.*?:\s*([\d\.]+)', linha)
                    if not mask_m:
                        # Tenta /24 como fallback
                        partes = ip.split('.')
                        partes[3] = '0'
                        cidr = '.'.join(partes) + '/24'
                    else:
                        try:
                            net = ipaddress.IPv4Network(f'{ip}/{mask_m.group(1)}', strict=False)
                            cidr = str(net)
                        except Exception:
                            partes = ip.split('.')
                            partes[3] = '0'
                            cidr = '.'.join(partes) + '/24'
                    if (iface, cidr) not in redes:
                        redes.append((iface, cidr))
        except Exception:
            pass

        # Fallback mais robusto: pega máscara separada
        if not redes:
            try:
                out = subprocess.check_output(['ipconfig'], stderr=subprocess.DEVNULL).decode('cp850', errors='ignore')
                ips, masks = [], []
                for linha in out.splitlines():
                    mi = re.search(r'IPv4.*?:\s*([\d\.]+)', linha)
                    mm = re.search(r'M[aá]scara.*?:\s*([\d\.]+)', linha)
                    if mi: ips.append(mi.group(1))
                    if mm: masks.append(mm.group(1))
                for ip, mask in zip(ips, masks):
                    if any(ip.startswith(p) for p in ignorar_prefixos):
                        continue
                    try:
                        net = ipaddress.IPv4Network(f'{ip}/{mask}', strict=False)
                        redes.append(('eth', str(net)))
                    except Exception:
                        pass
            except Exception:
                pass
    else:
        try:
            out = subprocess.check_output(['ip', 'route', 'show']).decode()
            for linha in out.splitlines():
                m = re.match(r'^(\d[\d\.]+/\d+)\s+dev\s+(\S+)', linha)
                if not m:
                    continue
                cidr, iface = m.group(1), m.group(2)
                if iface in ('lo',) or any(cidr.startswith(p) for p in ignorar_prefixos):
                    continue
                if (iface, cidr) not in redes:
                    redes.append((iface, cidr))
        except Exception:
            pass

    if not redes:
        # Último fallback: conecta ao 1.1.1.1 e vê qual IP local saiu
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.connect(('1.1.1.1', 80))
            ip_local = s.getsockname()[0]
            s.close()
            partes = ip_local.split('.')
            partes[3] = '0'
            redes.append(('eth0', '.'.join(partes) + '/24'))
        except Exception:
            redes.append(('eth0', '192.168.1.0/24'))

    return redes

def detecta_iface_para_rede(cidr: str) -> str:
    if IS_WINDOWS:
        return 'eth'
    base_ip = cidr.split('/')[0]
    try:
        out = subprocess.check_output(['ip', 'route', 'get', base_ip], stderr=subprocess.DEVNULL).decode()
        m = re.search(r'dev\s+(\S+)', out)
        if m:
            return m.group(1)
    except Exception:
        pass
    redes = detecta_todas_redes()
    return redes[0][0] if redes else 'eth0'

# ── Resolução de hostname ─────────────────────────────────────
def resolve_dns(ip):
    try:
        nome = socket.gethostbyaddr(ip)[0]
        return '' if nome == ip else nome
    except Exception:
        return ''

def resolve_netbios(ip):
    try:
        if IS_WINDOWS:
            out = subprocess.check_output(
                ['nbtstat', '-A', ip], stderr=subprocess.DEVNULL, timeout=3
            ).decode('cp850', errors='ignore')
            m = re.search(r'^\s*([A-Za-z0-9_-]{1,15})\s+<20>', out, re.MULTILINE)
            if m: return m.group(1)
            m = re.search(r'^\s*([A-Za-z0-9_-]{1,15})\s+<00>\s+UNIQUE', out, re.MULTILINE)
            return m.group(1) if m else ''
        else:
            out = subprocess.check_output(
                ['nmblookup', '-A', ip], stderr=subprocess.DEVNULL, timeout=2
            ).decode()
            m = re.search(r'^\s+(\S+)\s+<00>\s+-\s+[BH]', out, re.MULTILINE)
            return m.group(1) if m else ''
    except Exception:
        return ''

def resolve_snmp_sysname(ip):
    try:
        if extension_snmp_disponivel():
            import ctypes
            # usa subprocess python para não depender de lib
            pass
        if IS_WINDOWS:
            # tenta via snmpget se disponível (Net-SNMP para Windows)
            out = subprocess.check_output(
                ['snmpget', '-v2c', '-c', 'public', '-t', '1', '-r', '0', ip, 'sysName.0'],
                stderr=subprocess.DEVNULL, timeout=2
            ).decode()
        else:
            out = subprocess.check_output(
                ['snmpget', '-v2c', '-c', 'public', '-t', '1', '-r', '0', ip, 'sysName.0'],
                stderr=subprocess.DEVNULL, timeout=2
            ).decode()
        m = re.search(r'STRING:\s*(.+)', out)
        if m:
            nome = m.group(1).strip().strip('"')
            return nome if nome else ''
    except Exception:
        pass
    return ''

def extension_snmp_disponivel():
    # Checa se snmpget está no PATH
    try:
        subprocess.check_output(['snmpget', '--version'], stderr=subprocess.DEVNULL, timeout=1)
        return True
    except Exception:
        return False

def snmp_get_int(ip, oid):
    try:
        out = subprocess.check_output(
            ['snmpget', '-v2c', '-c', 'public', '-t', '1', '-r', '0', ip, oid],
            stderr=subprocess.DEVNULL, timeout=2
        ).decode()
        m = re.search(r':\s*(-?\d+)', out)
        return int(m.group(1)) if m else None
    except Exception:
        return None

def detecta_impressora_colorida(ip):
    niv = snmp_get_int(ip, '1.3.6.1.2.1.43.11.1.1.9.1.2')
    cap = snmp_get_int(ip, '1.3.6.1.2.1.43.11.1.1.8.1.2')
    if niv is None or cap is None or cap == -3 or cap <= 0:
        return False
    return True

# ── Ping ──────────────────────────────────────────────────────
def ping_host(ip: str, timeout_ms: int = 500) -> bool:
    try:
        if IS_WINDOWS:
            r = subprocess.call(
                ['ping', '-n', '1', '-w', str(timeout_ms), ip],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )
        else:
            r = subprocess.call(
                ['ping', '-c', '1', '-W', str(timeout_ms // 1000 or 1), ip],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )
        return r == 0
    except Exception:
        return False

# ── Scan de portas rápido ─────────────────────────────────────
PORTAS_SCAN = [22, 80, 135, 139, 443, 445, 515, 631, 3389, 5900, 8080, 9100]

def scan_portas(ip, timeout=0.5):
    abertas = []
    for porta in PORTAS_SCAN:
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.settimeout(timeout)
            if s.connect_ex((ip, porta)) == 0:
                abertas.append(porta)
            s.close()
        except Exception:
            pass
    return abertas

# ── MAC via ARP ───────────────────────────────────────────────
def get_mac(ip: str) -> str:
    try:
        if IS_WINDOWS:
            out = subprocess.check_output(['arp', '-a', ip], stderr=subprocess.DEVNULL).decode('cp850', errors='ignore')
            # Formato Windows: xx-xx-xx-xx-xx-xx
            m = re.search(r'([0-9a-fA-F]{2}(?:-[0-9a-fA-F]{2}){5})', out)
            if m:
                return m.group(1).replace('-', ':').upper()
        else:
            out = subprocess.check_output(['arp', '-n', ip], stderr=subprocess.DEVNULL).decode()
            m = re.search(r'([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})', out)
            if m:
                return m.group(1).upper()
    except Exception:
        pass
    return ''

# ── OUI lookup simples (fabricante a partir do MAC) ───────────
OUI_MAP = {}

def carregar_oui():
    global OUI_MAP
    # Tenta carregar arquivo oui.txt do ieee se existir localmente
    oui_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'oui.txt')
    if os.path.exists(oui_path):
        try:
            with open(oui_path, encoding='utf-8', errors='ignore') as f:
                for linha in f:
                    m = re.match(r'^([0-9A-F]{6})\s+\(base 16\)\s+(.+)', linha)
                    if m:
                        OUI_MAP[m.group(1)] = m.group(2).strip()
        except Exception:
            pass

def fabricante_do_mac(mac: str) -> str:
    if not mac or len(mac) < 8:
        return 'Desconhecido'
    oui = mac.replace(':', '').replace('-', '').upper()[:6]
    return OUI_MAP.get(oui, 'Desconhecido')

# ── Descoberta de hosts via ping sweep ───────────────────────
def scan_rede_windows(cidr: str, workers: int = 50) -> list:
    """
    Descobre hosts na rede fazendo ping sweep + coleta MAC via arp -a.
    Alternativa ao arp-scan para Windows.
    """
    print(f'  Ping sweep em {cidr} (workers={workers})...')
    try:
        rede = ipaddress.IPv4Network(cidr, strict=False)
    except Exception as e:
        print(f'  CIDR inválido: {e}')
        return []

    ips = [str(h) for h in rede.hosts()]
    if len(ips) > 1022:
        print(f'  Rede grande ({len(ips)} IPs) — limitando a /22 para não travar')
        ips = ips[:1022]

    ativos = []
    lock_ativos = __import__('threading').Lock()

    def testa(ip):
        if ping_host(ip, timeout_ms=300):
            with lock_ativos:
                ativos.append(ip)

    done = 0
    with ThreadPoolExecutor(max_workers=workers) as ex:
        futures = {ex.submit(testa, ip): ip for ip in ips}
        for f in as_completed(futures):
            done += 1
            if done % 20 == 0:
                print(f'  Ping: {done}/{len(ips)} testados, {len(ativos)} ativos', flush=True)
    print(f'\n  {len(ativos)} host(s) responderam ao ping')

    hosts = []
    for ip in sorted(ativos, key=lambda x: list(map(int, x.split('.')))):
        mac = get_mac(ip)
        fab = fabricante_do_mac(mac) if mac else 'Desconhecido'
        hosts.append({
            'ip': ip, 'mac': mac, 'fabricante': fab,
            'hostname': '', 'netbios': '', 'snmp_nome': '',
            'portas': [], 'tipo': '', 'rede': cidr, 'setor': '',
        })
    return hosts

def scan_arpscan(iface: str, alvo: str, workers: int = 50) -> list:
    if IS_WINDOWS:
        return scan_rede_windows(alvo, workers)
    # Linux: tenta arp-scan primeiro
    cmd = ['arp-scan', '-I', iface, alvo]
    print(f'  Rodando: {" ".join(cmd)}')
    try:
        out = subprocess.check_output(cmd, stderr=subprocess.DEVNULL).decode()
    except FileNotFoundError:
        print('  arp-scan não encontrado — usando ping sweep como fallback')
        return scan_rede_windows(alvo, workers)
    except subprocess.CalledProcessError as e:
        print(f'  ERRO arp-scan: {e}')
        return scan_rede_windows(alvo, workers)

    hosts = []
    for linha in out.splitlines():
        m = re.match(r'^(\d+\.\d+\.\d+\.\d+)\t([0-9a-fA-F:]{17})\t(.*)$', linha)
        if not m:
            continue
        ip, mac, fab = m.group(1), m.group(2).upper(), m.group(3).strip()
        if '(DUP:' in fab:
            continue
        hosts.append({'ip': ip, 'mac': mac, 'fabricante': fab,
                      'hostname': '', 'netbios': '', 'snmp_nome': '',
                      'portas': [], 'tipo': '', 'rede': alvo, 'setor': ''})
    return hosts

# ── Enriquece host (paralelo) ─────────────────────────────────
def enriquece(h):
    ip = h['ip']
    h['hostname']  = resolve_dns(ip)
    h['netbios']   = resolve_netbios(ip)
    h['portas']    = scan_portas(ip)
    h['snmp_nome'] = resolve_snmp_sysname(ip)
    h['tipo']      = classifica_tipo(h['fabricante'], h['portas'])
    if 'Impressora' in h['tipo'] and 'Etiqueta' not in h['tipo']:
        if detecta_impressora_colorida(ip):
            h['tipo'] = 'Impressora Colorida'
    h['nome_host'] = h['netbios'] or h['snmp_nome'] or h['hostname']
    h['setor']     = infere_setor(h['nome_host'])
    return h

# ── Inferência de setor ───────────────────────────────────────
SETOR_PADROES = [
    (r'fatu|fat0|faturament', 'Faturamento'),
    (r'rect|rec-t|recep',     'Recepção'),
    (r'rec-us|rec-usg|rec-rm|recrm|rec01|rec-med', 'Recepção'),
    (r'fin\d|fin-',           'Financeiro'),
    (r'tel\d|telefon',        'Telefonia'),
    (r'supr',                 'Suprimentos'),
    (r'consul',               'Consultório'),
    (r'atec|at-|atec-',       'Assistência Técnica'),
    (r'vac-|vac0',            'Vacina'),
    (r'result',               'Resultado'),
    (r'bd-',                  'Banco de Dados'),
    (r'srv|server|serv',      'Servidor'),
    (r'ti-|supervisor.ti|gerente.tec', 'TI'),
    (r'admin',                'Administrativo'),
    (r'coleta',               'Coleta'),
    (r'monitor',              'Monitoramento'),
    (r'totem',                'Totem/Autoatendimento'),
    (r'img-|imagem',          'Imagem'),
    (r'laudo',                'Laudos'),
    (r'piso',                 'Andar/Piso'),
]

def infere_setor(hostname: str) -> str:
    if not hostname:
        return ''
    h = hostname.lower()
    for pattern, setor in SETOR_PADROES:
        if re.search(pattern, h):
            return setor
    return ''

# ── Limpeza do fabricante ─────────────────────────────────────
MARCA_ALIAS = {
    'aruba, a hewlett packard enterprise company': 'Aruba/HP',
    'hewlett packard enterprise':  'HP Enterprise',
    'hewlett packard':             'HP',
    'hp ':                         'HP',
    'asustek computer':            'ASUS',
    'asrock incorporation':        'ASRock',
    'micro-star intl':             'MSI',
    'giga-byte technology':        'Gigabyte',
    'elitegroup computer systems': 'ECS',
    'pegatron corporation':        'ASUS/Pegatron',
    'hon hai precision':           'Foxconn',
    'intel corporate':             'Intel',
    'realtek semiconductor':       'Realtek',
    'kyocera document solutions':  'Kyocera',
    'zebra technologies':          'Zebra',
    'bematech international':      'Bematech',
    'xerox corporation':           'Xerox',
    'samsung electronics':         'Samsung',
    'lg electronics':              'LG',
    'super micro computer':        'Supermicro',
    'cisco systems':               'Cisco',
    'ubiquiti networks':           'Ubiquiti',
    'routerboard.com':             'MikroTik',
    'synology incorporated':       'Synology',
    'microsoft corporation':       'Microsoft',
    'ieee registration authority': 'IEEE/Genérico',
    '(unknown: locally administered)': 'MAC Local (VM/VPN)',
    '(unknown)':                   'Desconhecido',
    '(private)':                   'Privado',
    'private':                     'Privado',
}

def marca_limpa(fabricante: str) -> str:
    fab_lower = fabricante.lower().strip()
    for chave, alias in MARCA_ALIAS.items():
        if chave in fab_lower:
            return alias
    nome = fabricante.split(',')[0].split(' Inc')[0].split(' Co.')[0].strip()
    return nome[:30] if nome else 'Desconhecido'

# ── Export ────────────────────────────────────────────────────
def exporta_json(hosts, arquivo):
    dados = {
        'escaneado_em': datetime.now().isoformat(),
        'total': len(hosts),
        'hosts': [{
            'ip':         h['ip'],
            'mac':        h['mac'],
            'hostname':   h.get('nome_host', '') or '',
            'fabricante': h.get('fabricante', '') or '',
            'tipo':       h.get('tipo', '') or '',
            'marca':      marca_limpa(h.get('fabricante', '') or ''),
            'portas':     h.get('portas', []),
            'rede':       h.get('rede', '') or '',
            'setor':      h.get('setor', '') or '',
        } for h in hosts]
    }
    tmp = arquivo + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(dados, f, ensure_ascii=False, indent=2)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, arquivo)

def exporta_csv(hosts, arquivo):
    cabecalho = [
        'tipo', 'marca', 'modelo', 'numero_serie', 'patrimonio',
        'setor', 'responsavel_nome', 'status',
        'data_aquisicao', 'valor', 'garantia_ate', 'imei', 'observacoes',
        'ip_detectado', 'hostname', 'mac'
    ]
    with open(arquivo, 'w', newline='', encoding='utf-8-sig') as f:
        w = csv.writer(f, delimiter=';')
        w.writerow(cabecalho)
        for h in hosts:
            portas_str = ','.join(map(str, h['portas'])) if h['portas'] else ''
            obs_parts = [f"IP: {h['ip']}", f"MAC: {h['mac']}"]
            if h['rede']:      obs_parts.append(f"Rede: {h['rede']}")
            if h['nome_host']: obs_parts.append(f"Host: {h['nome_host']}")
            if portas_str:     obs_parts.append(f"Portas: {portas_str}")
            w.writerow([
                h['tipo'], marca_limpa(h['fabricante']), h['nome_host'], '', '',
                h.get('setor', ''), '', 'Disponível', '', '', '', '',
                ' | '.join(obs_parts), h['ip'], h['nome_host'], h['mac'],
            ])

# ── Main ──────────────────────────────────────────────────────
def main():
    args = sys.argv[1:]
    workers = 50
    if args and args[-1].isdigit():
        workers = int(args.pop())

    carregar_oui()

    print('=' * 65)
    print(f'  HelpTI — Scanner de Rede v4  [{platform.system()}]')
    print('=' * 65)

    if args:
        alvos_iface = [(detecta_iface_para_rede(cidr), cidr) for cidr in args]
        print('  Modo        : manual')
    else:
        alvos_iface = detecta_todas_redes()
        print('  Modo        : auto-detecção')

    print(f'  Paralelo    : {workers} threads')
    if IS_WINDOWS:
        print('  Método      : ping sweep + arp -a (Windows)')
    else:
        print('  Método      : arp-scan (Linux) com fallback ping sweep')
    print()
    for iface, cidr in alvos_iface:
        print(f'  → {cidr:<20} via {iface}')
    print()

    todos_hosts = []
    t0 = datetime.now()

    for iface, alvo in alvos_iface:
        print(f'  [{alvo}] via {iface}')
        hosts = scan_arpscan(iface, alvo, workers)
        print(f'  [{alvo}] {len(hosts)} host(s) descoberto(s)')
        todos_hosts.extend(hosts)

    # Deduplica por IP (no Windows sem ARP passivo pode haver duplicatas por CIDR)
    vistos = {}
    for h in todos_hosts:
        key = h['mac'] if h['mac'] else h['ip']
        if key not in vistos:
            vistos[key] = h
        elif len(h['portas']) > len(vistos[key]['portas']):
            vistos[key] = h
    duplicados = len(todos_hosts) - len(vistos)
    todos_hosts = list(vistos.values())
    if duplicados:
        print(f'  {duplicados} duplicado(s) removido(s)')

    print()
    print(f'  Total descoberto: {len(todos_hosts)} host(s) únicos')
    print(f'  Enriquecendo dados (DNS, NetBIOS, portas)...')

    t1 = datetime.now()
    with ThreadPoolExecutor(max_workers=workers) as ex:
        futures = {ex.submit(enriquece, h): h for h in todos_hosts}
        done = 0
        for f in as_completed(futures):
            done += 1
            h = futures[f]
            print(f'  [{done}/{len(todos_hosts)}] {h["ip"]:16} {h.get("nome_host",""):20}', end='\r')
    t_enrich = (datetime.now() - t1).total_seconds()
    print(f'\n  Enriquecimento concluído em {t_enrich:.1f}s')

    todos_hosts.sort(key=lambda x: (x['rede'], list(map(int, x['ip'].split('.')))))

    print()
    rede_atual = None
    contadores = {}
    for h in todos_hosts:
        if h['rede'] != rede_atual:
            rede_atual = h['rede']
            print(f"\n  ── Rede: {rede_atual} ──")
            print(f"  {'IP':<16} {'MAC':<19} {'TIPO':<22} {'MARCA':<18} {'HOST/NOME':<20} {'SETOR'}")
            print('  ' + '-' * 110)
        mac   = h['mac'] or '—'
        marca = marca_limpa(h['fabricante'])[:16] if h['fabricante'] else '—'
        nome  = h['nome_host'][:18] if h['nome_host'] else '—'
        tipo  = h['tipo'][:20]
        setor = h.get('setor', '')[:16] or '—'
        print(f"  {h['ip']:<16} {mac:<19} {tipo:<22} {marca:<18} {nome:<20} {setor}")
        contadores[h['tipo']] = contadores.get(h['tipo'], 0) + 1

    print()
    print('  RESUMO POR TIPO:')
    for tipo, qtd in sorted(contadores.items(), key=lambda x: -x[1]):
        print(f'    {tipo:<30} {qtd} equipamento(s)')

    _base = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'storage', 'scans')
    os.makedirs(_base, exist_ok=True)
    arquivo = os.path.join(_base, f"scan_rede_{datetime.now().strftime('%Y%m%d_%H%M')}.csv")
    exporta_csv(todos_hosts, arquivo)
    caminho = os.path.abspath(arquivo)

    json_arquivo = os.path.join(_base, 'scan_ultimo.json')
    exporta_json(todos_hosts, json_arquivo)

    total = (datetime.now() - t0).total_seconds()
    print()
    print(f'  CSV exportado : {caminho}')
    print(f'  JSON sync     : {json_arquivo}')
    print(f'  Tempo total   : {total:.1f}s  ({len(todos_hosts)} hosts em {len(alvos_iface)} rede(s))')
    print()
    print('=' * 65)

if __name__ == '__main__':
    main()
