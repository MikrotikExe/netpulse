-- DudeWeb schéma (SQLite)
CREATE TABLE IF NOT EXISTS maps(
  id INTEGER PRIMARY KEY, name TEXT, image_id INTEGER, canvas_id INTEGER, sort_order INTEGER);
CREATE TABLE IF NOT EXISTS device_types(
  id INTEGER PRIMARY KEY, name TEXT, icon TEXT);
CREATE TABLE IF NOT EXISTS devices(
  id INTEGER PRIMARY KEY, name TEXT, ip TEXT, dns TEXT,
  snmp_profile INTEGER, username TEXT, password TEXT, type_id INTEGER,
  status TEXT DEFAULT 'unknown', last_check TEXT, rtt REAL, down_since TEXT, monitored INTEGER DEFAULT 1);
CREATE TABLE IF NOT EXISTS map_nodes(
  id INTEGER PRIMARY KEY, map_id INTEGER, kind TEXT DEFAULT 'device',
  device_id INTEGER, submap_id INTEGER, x INTEGER, y INTEGER, image INTEGER, label TEXT);
CREATE TABLE IF NOT EXISTS map_links(
  id INTEGER PRIMARY KEY, map_id INTEGER, from_node INTEGER,
  to_node INTEGER, width INTEGER, style INTEGER, thickness INTEGER, ltype TEXT, snmp_device INTEGER, snmp_ifindex INTEGER, snmp_type INTEGER, label TEXT);
CREATE TABLE IF NOT EXISTS services(
  id INTEGER PRIMARY KEY, device_id INTEGER, name TEXT,
  probe_id INTEGER, ptype TEXT, port INTEGER, enabled INTEGER, down INTEGER, acked INTEGER,
  status TEXT DEFAULT 'unknown', last_check TEXT);
CREATE TABLE IF NOT EXISTS status_history(
  id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER,
  ts TEXT, status TEXT, rtt REAL);
CREATE TABLE IF NOT EXISTS events(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, device_id INTEGER,
  device_name TEXT, ip TEXT, status TEXT, message TEXT);
CREATE INDEX IF NOT EXISTS idx_nodes_map ON map_nodes(map_id);
CREATE INDEX IF NOT EXISTS idx_links_map ON map_links(map_id);
CREATE INDEX IF NOT EXISTS idx_svc_dev ON services(device_id);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);
CREATE INDEX IF NOT EXISTS idx_sh_dev_ts ON status_history(device_id, ts);
CREATE INDEX IF NOT EXISTS idx_sh_ts ON status_history(ts);
CREATE INDEX IF NOT EXISTS idx_tr_ts ON traffic_history(ts);
CREATE TABLE IF NOT EXISTS probes(
  id INTEGER PRIMARY KEY, name TEXT, type TEXT, port INT, dns_name TEXT);
CREATE TABLE IF NOT EXISTS outages(
  id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, service TEXT, started TEXT, ended TEXT , duration INT);
CREATE TABLE IF NOT EXISTS link_types(id INTEGER PRIMARY KEY, name TEXT, style INTEGER, thickness INTEGER);
CREATE TABLE IF NOT EXISTS snmp_profiles(id INTEGER PRIMARY KEY, name TEXT, community TEXT, version INTEGER, port INTEGER, sec_name TEXT, auth_pass TEXT, priv_pass TEXT, auth_proto TEXT, priv_proto TEXT);
CREATE TABLE IF NOT EXISTS link_traffic(link_id INTEGER PRIMARY KEY, in_oct INTEGER, out_oct INTEGER, ts TEXT, rx_bps DOUBLE, tx_bps DOUBLE, speed_bps DOUBLE);
