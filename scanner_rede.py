#!/usr/bin/env python3
"""
HelpTI — Scanner de Rede v3
Uso: python3 scanner_rede.py [rede1/cidr] [rede2/cidr] ...
Ex:  python3 scanner_rede.py 192.168.1.0/24 10.0.1.0/24
     python3 scanner_rede.py          # detecta rede automaticamente
"""

import subprocess, socket, csv, sys, os, re, json
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

# ── Fabricantes com prioridade absoluta (verificados ANTES das portas) ───
# Evita que NAS, switches e APs sejam confundidos por portas de impressão
FABRICANTE_PRIORIDADE = {
    'synology':    'Servidor NAS',
    'qnap':        'Servidor NAS',
    'netgear':     'Switch',
    'cisco':       'Switch',
    'routerboard': 'Roteador MikroTik',
    'ubiquiti':    'Access Point',
    'aruba':       'Access Point',      # Aruba Networks (HP Enterprise)
    'intelbras':   'Switch/AP Intelbras',
    '8devices':    'Roteador',
    'weintek':     'IHM/Painel',
    'siemens':     'Equipamento Médico',
    'sord':        'Terminal',
    'tecnomen':    'Equipamento Especial',
    'control id':  'Controle de Acesso',
    'control-id':  'Controle de Acesso',
    'microsoft':   'Servidor',          # MACs Microsoft = VMs Hyper-V
    'lantronix':   'Servidor',
    'fr. sauter':  'Equipamento Especial',
}

# ── Fabricantes comuns de computadores ────────────────────
TIPO_POR_FABRICANTE = {
    # Impressoras
    'kyocera':    'Impressora',
    'xerox':      'Impressora',
    'zebra':      'Impressora Etiqueta',
    'bematech':   'Impressora',
    'brother':    'Impressora',
    'lexmark':    'Impressora',
    # Samsung fabrica tanto PCs quanto impressoras → porta decide
    # Computadores / Workstations
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
    'lg electr':  'Desktop',            # LG Electronics / LG ELECTRONICS
    'comtec':     'Desktop',
    'micro-star': 'Desktop',            # MSI
    'giga-byte':  'Desktop',            # Gigabyte
    'gigabyte':   'Desktop',
    'intel corp': 'Desktop',
    'hon hai':    'Desktop',            # Foxconn
    'cal-comp':   'Desktop',
    # Servidores
    'super micro':'Servidor',
    'advantech':  'Servidor',
    'congatec':   'Servidor',
}

def classifica_tipo(fabricante: str, portas: list) -> str:
    fab = fabricante.lower()

    # 1. Fabricantes prioritários (NAS, rede, controle) — imunes a portas
    for chave, tipo in FABRICANTE_PRIORIDADE.items():
        if chave in fab:
            return tipo

    # 2. Portas Windows definitivas (SMB+RPC) → Desktop, mesmo que tenha 9100
    #    PCs com compartilhamento de impressora abrem 9100, mas são Desktops
    if 445 in portas and 135 in portas:
        return 'Desktop'
    if 3389 in portas:
        return 'Desktop'

    # 3. Porta RAW de impressão (9100) — só impressora se não for Windows
    if 9100 in portas:
        return 'Impressora'

    # 4. Fabricante comum
    for chave, tipo in TIPO_POR_FABRICANTE.items():
        if chave in fab:
            return tipo

    # 5. Portas de impressão secundárias (LPD/IPP) — só se fabricante não resolveu
    if 515 in portas or 631 in portas:
        return 'Impressora'

    return 'Computador'  # padrão

# ── Detecta interface e rede ──────────────────────────────
def detecta_todas_redes() -> list:
    """
    Lê 'ip route' e retorna lista de (iface, cidr) para todas as redes locais
    conectadas diretamente (ignora loopback, Docker, VPN genéricas).
    """
    ignorar_prefixos = ('127.', '169.254.', '172.17.', '172.18.',
                        '172.19.', '172.2', '::')
    redes = []
    try:
        out = subprocess.check_output('ip route show', shell=True).decode()
        for linha in out.splitlines():
            # Linhas de rota direta: "192.168.1.0/24 dev enp3s0 proto kernel ..."
            m = re.match(r'^(\d[\d\.]+/\d+)\s+dev\s+(\S+)', linha)
            if not m:
                continue
            cidr, iface = m.group(1), m.group(2)
            if iface in ('lo',) or any(cidr.startswith(p) for p in ignorar_prefixos):
                continue
            if (cidr, iface) not in redes:
                redes.append((iface, cidr))
    except Exception:
        pass

    if not redes:
        # Fallback: pega a rota padrão
        try:
            out = subprocess.check_output("ip route get 1.1.1.1 2>/dev/null", shell=True).decode()
            iface_m = re.search(r'dev\s+(\S+)', out)
            src_m   = re.search(r'src\s+([\d\.]+)', out)
            if iface_m and src_m:
                p = src_m.group(1).split('.')
                p[3] = '0'
                redes.append((iface_m.group(1), '.'.join(p) + '/24'))
        except Exception:
            redes.append(('eth0', '192.168.1.0/24'))
    return redes

def detecta_iface_para_rede(cidr: str) -> str:
    """Descobre qual interface usar para atingir o CIDR."""
    base_ip = cidr.split('/')[0]
    try:
        out = subprocess.check_output(
            f'ip route get {base_ip} 2>/dev/null', shell=True
        ).decode()
        m = re.search(r'dev\s+(\S+)', out)
        if m:
            return m.group(1)
    except Exception:
        pass
    redes = detecta_todas_redes()
    return redes[0][0] if redes else 'eth0'

# ── Resolução de hostname ─────────────────────────────────
def resolve_dns(ip):
    try:
        nome = socket.gethostbyaddr(ip)[0]
        return '' if nome == ip else nome
    except Exception:
        return ''

def resolve_netbios(ip):
    """Tenta pegar o nome NetBIOS (Windows) via nmblookup"""
    try:
        out = subprocess.check_output(
            ['nmblookup', '-A', ip], stderr=subprocess.DEVNULL, timeout=2
        ).decode()
        m = re.search(r'^\s+(\S+)\s+<00>\s+-\s+[BH]', out, re.MULTILINE)
        return m.group(1) if m else ''
    except Exception:
        return ''

def resolve_snmp_sysname(ip):
    """Tenta pegar o nome via SNMP sysName (funciona em impressoras, switches, APs)."""
    try:
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

def snmp_get_int(ip, oid):
    """Retorna inteiro de OID SNMP ou None."""
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
    """Retorna True se a impressora responde SNMP com canal de toner ciano (colorida)."""
    niv = snmp_get_int(ip, '1.3.6.1.2.1.43.11.1.1.9.1.2')  # toner ciano nível
    cap = snmp_get_int(ip, '1.3.6.1.2.1.43.11.1.1.8.1.2')  # toner ciano capacidade
    # Capacidade -3 = contínuo (térmicas), None = sem resposta
    if niv is None or cap is None or cap == -3 or cap <= 0:
        return False
    return True

# ── Scan de portas rápido ─────────────────────────────────
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

# ── arp-scan ──────────────────────────────────────────────
def scan_arpscan(iface, alvo):
    cmd = ['arp-scan', '-I', iface, alvo]
    print(f'  Rodando: {" ".join(cmd)}')
    try:
        out = subprocess.check_output(cmd, stderr=subprocess.DEVNULL).decode()
    except FileNotFoundError:
        print('  ERRO: arp-scan não encontrado. sudo apt install arp-scan')
        sys.exit(1)
    except subprocess.CalledProcessError as e:
        print(f'  ERRO: {e}')
        sys.exit(1)

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

# ── Enriquece host (paralelo) ─────────────────────────────
def enriquece(h):
    ip = h['ip']
    # DNS
    h['hostname'] = resolve_dns(ip)
    # NetBIOS (Windows)
    h['netbios'] = resolve_netbios(ip)
    # Portas
    h['portas'] = scan_portas(ip)
    # SNMP sysName (impressoras, switches, APs, roteadores)
    h['snmp_nome'] = resolve_snmp_sysname(ip)
    # Tipo
    h['tipo'] = classifica_tipo(h['fabricante'], h['portas'])
    # Refina: impressora colorida via SNMP toner ciano
    if 'Impressora' in h['tipo'] and 'Etiqueta' not in h['tipo']:
        if detecta_impressora_colorida(ip):
            h['tipo'] = 'Impressora Colorida'
    # Melhor nome: NetBIOS > SNMP > DNS
    h['nome_host'] = h['netbios'] or h['snmp_nome'] or h['hostname']
    h['setor']     = infere_setor(h['nome_host'])
    return h

# ── Inferência de setor pelo hostname ────────────────────
# Padrões comuns em ambientes de saúde/empresa; editável pelo TI
SETOR_PADROES = [
    # (regex_no_hostname,  setor)
    (r'fatu|fat0|faturament', 'Faturamento'),
    (r'rect|rec-t|recep',    'Recepção'),
    (r'rec-us|rec-usg|rec-rm|recrm|rec-mulher|rec01|rec-med', 'Recepção'),
    (r'fin\d|fin-',          'Financeiro'),
    (r'tel\d|telefon',       'Telefonia'),
    (r'supr',                'Suprimentos'),
    (r'consul',              'Consultório'),
    (r'atec|at-|atec-',      'Assistência Técnica'),
    (r'vac-|vac0',           'Vacina'),
    (r'result',              'Resultado'),
    (r'bd-',                 'Banco de Dados'),
    (r'srv|server|serv',     'Servidor'),
    (r'ti-|supervisor.ti|gerente.tec|diemisson', 'TI'),
    (r'admin',               'Administrativo'),
    (r'coleta',              'Coleta'),
    (r'monitor',             'Monitoramento'),
    (r'totem',               'Totem/Autoatendimento'),
    (r'img-|imagem',         'Imagem'),
    (r'laudo|telela',        'Laudos'),
    (r'piso',                'Andar/Piso'),
    (r'resso',               'Ressocialização'),
    (r'srvauto|srvauto',     'Automação'),
    (r'srvad|srvauto',       'Active Directory'),
    (r'apolod',              'Apolod/Coleta'),
    (r'ati0',                'ATI'),
    (r'rcp-|rcp0',           'Recepção'),
]

def infere_setor(hostname: str) -> str:
    if not hostname:
        return ''
    h = hostname.lower()
    for pattern, setor in SETOR_PADROES:
        if re.search(pattern, h):
            return setor
    return ''

# ── Limpeza do nome do fabricante ────────────────────────
MARCA_ALIAS = {
    'aruba, a hewlett packard enterprise company': 'Aruba/HP',
    'hewlett packard enterprise':  'HP Enterprise',
    'hewlett packard':             'HP',
    'hp ':                         'HP',
    'asustek computer':            'ASUS',
    'asrock incorporation':        'ASRock',
    'micro-star intl':             'MSI',
    'micro-star int\'l':           'MSI',
    'giga-byte technology':        'Gigabyte',
    'elitegroup computer systems': 'ECS',
    'pegatron corporation':        'ASUS/Pegatron',
    'hon hai precision':           'Foxconn',
    'intel corporate':             'Intel',
    'realtek semiconductor':       'Realtek',
    'kyocera document solutions':  'Kyocera',
    'kyocera display':             'Kyocera',
    'zebra technologies':          'Zebra',
    'bematech international':      'Bematech',
    'xerox corporation':           'Xerox',
    'samsung electronics':         'Samsung',
    'lg electronics':              'LG',
    'lg electro':                  'LG',
    'super micro computer':        'Supermicro',
    'advantech technology':        'Advantech',
    'cisco systems':               'Cisco',
    'ubiquiti networks':           'Ubiquiti',
    'routerboard.com':             'MikroTik',
    'synology incorporated':       'Synology',
    'comtec systems':              'Comtec',
    'biostar microtech':           'Biostar',
    'control id':                  'Control iD',
    'microsoft corporation':       'Microsoft',
    'sord computer':               'SORD',
    'tecnomen oy':                 'Tecnomen',
    'weintek labs':                'Weintek',
    'siemens ag':                  'Siemens',
    'fr. sauter':                  'Sauter',
    'lantronix':                   'Lantronix',
    'ieee registration authority': 'IEEE/Genérico',
    '(unknown: locally administered)': 'MAC Local (VM/VPN)',
    '(unknown)':                   'Desconhecido',
    '(private)':                   'Privado',
    'private':                     'Privado',
}

def marca_limpa(fabricante: str) -> str:
    """Converte nome OUI verbose em marca curta reconhecível."""
    fab_lower = fabricante.lower().strip()
    for chave, alias in MARCA_ALIAS.items():
        if chave in fab_lower:
            return alias
    # Fallback: pega até a primeira vírgula ou ponto final + limita 30 chars
    nome = fabricante.split(',')[0].split(' Inc')[0].split(' Co.')[0].strip()
    return nome[:30] if nome else 'Desconhecido'

# ── Exporta JSON para cron PHP ────────────────────────────
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
    with open(arquivo, 'w', encoding='utf-8') as f:
        json.dump(dados, f, ensure_ascii=False, indent=2)

# ── Exporta CSV ───────────────────────────────────────────
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
            # Observações: máximo de informações para rastreabilidade
            obs_parts = []
            obs_parts.append(f"IP: {h['ip']}")
            obs_parts.append(f"MAC: {h['mac']}")
            if h['rede']:      obs_parts.append(f"Rede: {h['rede']}")
            if h['nome_host']: obs_parts.append(f"Host: {h['nome_host']}")
            if portas_str:     obs_parts.append(f"Portas: {portas_str}")
            if h['fabricante'] and h['fabricante'] not in ('(Unknown)', '(Unknown: locally administered)'):
                obs_parts.append(f"OUI: {h['fabricante'][:40]}")

            w.writerow([
                h['tipo'],
                marca_limpa(h['fabricante']),
                h['nome_host'],          # modelo = hostname (identificador do PC)
                '',                      # numero_serie (preencher manualmente)
                '',                      # patrimonio  (preencher manualmente)
                h.get('setor', ''),      # setor inferido pelo hostname
                '',                      # responsavel_nome (preencher manualmente)
                'Disponível',
                '', '', '', '',          # data_aq, valor, garantia, imei
                ' | '.join(obs_parts),
                h['ip'],
                h['nome_host'],
                h['mac'],
            ])

# ── Main ──────────────────────────────────────────────────
def main():
    # Último arg numérico puro = número de workers
    args = sys.argv[1:]
    workers = 50
    if args and args[-1].isdigit():
        workers = int(args.pop())

    print('=' * 65)
    print('  HelpTI — Scanner de Rede v3')
    print('=' * 65)

    if args:
        # CIDRs informados manualmente
        alvos_iface = [(detecta_iface_para_rede(cidr), cidr) for cidr in args]
        print('  Modo        : manual')
    else:
        # Auto-detecta todas as redes locais
        alvos_iface = detecta_todas_redes()
        print('  Modo        : auto-detecção')

    print(f'  Paralelo    : {workers} threads')
    print()
    for iface, cidr in alvos_iface:
        print(f'  → {cidr:<20} via {iface}')
    print()

    todos_hosts = []
    t0 = datetime.now()

    for iface, alvo in alvos_iface:
        print(f'  [{alvo}] via {iface}')

        # Fase 1: descoberta ARP
        hosts = scan_arpscan(iface, alvo)
        print(f'  [{alvo}] {len(hosts)} host(s) descoberto(s) via ARP')
        todos_hosts.extend(hosts)

    # Deduplicar por MAC (mesmo equipamento pode aparecer em múltiplas redes/interfaces)
    vistos = {}
    for h in todos_hosts:
        mac = h['mac']
        if mac not in vistos:
            vistos[mac] = h
        else:
            # Mantém o host com mais informações (maior número de portas como critério)
            if len(h['portas']) > len(vistos[mac]['portas']):
                vistos[mac] = h
    duplicados = len(todos_hosts) - len(vistos)
    todos_hosts = list(vistos.values())
    if duplicados:
        print(f'  {duplicados} duplicado(s) removido(s) por MAC')

    print()
    print(f'  Total descoberto: {len(todos_hosts)} host(s) únicos')
    print(f'  Enriquecendo dados (DNS, NetBIOS, SNMP, portas)...')

    # Fase 2: enriquecimento paralelo de todos os hosts
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

    # Ordena por rede e depois por IP
    todos_hosts.sort(key=lambda x: (x['rede'], list(map(int, x['ip'].split('.')))))

    # Tabela resumida por rede
    print()
    rede_atual = None
    contadores = {}
    for h in todos_hosts:
        if h['rede'] != rede_atual:
            rede_atual = h['rede']
            print(f"\n  ── Rede: {rede_atual} ──")
            print(f"  {'IP':<16} {'MAC':<19} {'TIPO':<22} {'MARCA':<18} {'HOST/NOME':<20} {'SETOR':<18} {'PORTAS'}")
            print('  ' + '-' * 120)
        mac   = h['mac']
        marca = marca_limpa(h['fabricante'])[:16] if h['fabricante'] else '—'
        nome  = h['nome_host'][:18] if h['nome_host'] else '—'
        tipo  = h['tipo'][:20]
        setor = h.get('setor','')[:16] or '—'
        portas = ','.join(map(str, h['portas'])) or '—'
        print(f"  {h['ip']:<16} {mac:<19} {tipo:<22} {marca:<18} {nome:<20} {setor:<18} {portas}")
        contadores[h['tipo']] = contadores.get(h['tipo'], 0) + 1

    # Resumo por tipo
    print()
    print('  RESUMO POR TIPO:')
    for tipo, qtd in sorted(contadores.items(), key=lambda x: -x[1]):
        print(f'    {tipo:<30} {qtd} equipamento(s)')

    # Exporta CSV
    arquivo = f"scan_rede_{datetime.now().strftime('%Y%m%d_%H%M')}.csv"
    exporta_csv(todos_hosts, arquivo)
    caminho = os.path.abspath(arquivo)

    # Exporta JSON para reconciliação automática pelo cron PHP
    json_arquivo = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'scan_ultimo.json')
    exporta_json(todos_hosts, json_arquivo)

    total = (datetime.now() - t0).total_seconds()
    print()
    print(f'  CSV exportado : {caminho}')
    print(f'  JSON sync     : {json_arquivo}')
    print(f'  Tempo total   : {total:.1f}s  ({len(todos_hosts)} hosts em {len(alvos_iface)} rede(s))')
    print()
    print('  Próximos passos:')
    print('  1. Abra o CSV no Excel / LibreOffice')
    print('  2. Ajuste: modelo, numero_serie, setor, responsavel')
    print('  3. Importe em: http://localhost:8080/importar_inventario.php')
    print('=' * 65)

if __name__ == '__main__':
    main()
