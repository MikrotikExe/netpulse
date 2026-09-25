-- NetPulse schéma (MySQL 8 / MariaDB 10.6+). Databázu vytvor s CHARACTER SET utf8mb4.
CREATE TABLE IF NOT EXISTS maps(
  id BIGINT PRIMARY KEY, name VARCHAR(255), image_id BIGINT, canvas_id BIGINT, sort_order INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS device_types(
  id BIGINT PRIMARY KEY, name VARCHAR(255), icon VARCHAR(128)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS devices(
  id BIGINT PRIMARY KEY, name VARCHAR(255), ip VARCHAR(64), dns VARCHAR(255),
  snmp_profile BIGINT, username VARCHAR(128), password VARCHAR(255), type_id BIGINT,
  status VARCHAR(16) DEFAULT 'unknown', last_check DATETIME NULL, rtt DOUBLE NULL, down_since DATETIME NULL, down_ts BIGINT NULL,
  monitored TINYINT DEFAULT 1, notified VARCHAR(16) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS map_nodes(
  id BIGINT PRIMARY KEY, map_id BIGINT, kind VARCHAR(16) DEFAULT 'device',
  device_id BIGINT, submap_id BIGINT, x INT, y INT, image BIGINT, label TEXT, INDEX idx_nodes_map(map_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS map_links(
  id BIGINT PRIMARY KEY, map_id BIGINT, from_node BIGINT,
  to_node BIGINT, width INT, style INT, thickness INT, ltype VARCHAR(64), snmp_device BIGINT, snmp_ifindex INT, snmp_type INT, label TEXT,
  INDEX idx_links_map(map_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS services(
  id BIGINT PRIMARY KEY, device_id BIGINT, name VARCHAR(128),
  probe_id BIGINT, ptype VARCHAR(16), port INT, enabled INT, down INT, acked INT,
  status VARCHAR(16) DEFAULT 'unknown', last_check DATETIME NULL, INDEX idx_svc_dev(device_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS status_history(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, device_id BIGINT,
  ts DATETIME, status VARCHAR(16), rtt DOUBLE,
  INDEX idx_sh_dev_ts(device_id, ts), INDEX idx_sh_ts(ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS events(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, ts DATETIME, device_id BIGINT,
  device_name VARCHAR(255), ip VARCHAR(64), status VARCHAR(16), message TEXT, INDEX idx_events_ts(ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS probes(
  id BIGINT PRIMARY KEY, name VARCHAR(64), type VARCHAR(16), port INT, dns_name VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS outages(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, device_id BIGINT, service VARCHAR(64),
  started DATETIME, started_ts BIGINT NULL, ended DATETIME NULL, duration INT,
  INDEX idx_out_dev(device_id, ended)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS link_types(id BIGINT PRIMARY KEY, name VARCHAR(64), style INT, thickness INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS snmp_profiles(id BIGINT PRIMARY KEY, name VARCHAR(64), community VARCHAR(64), version INT, port INT,
  sec_name VARCHAR(128), auth_pass VARCHAR(128), priv_pass VARCHAR(128), auth_proto VARCHAR(8), priv_proto VARCHAR(8)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS link_traffic(link_id BIGINT PRIMARY KEY, in_oct DOUBLE, out_oct DOUBLE, ts DATETIME, ts_unix BIGINT NULL,
  ctype VARCHAR(4) NULL, uptime BIGINT NULL, rx_bps DOUBLE, tx_bps DOUBLE, speed_bps DOUBLE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS traffic_history(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, link_id BIGINT, ts DATETIME, rx_bps DOUBLE, tx_bps DOUBLE,
  INDEX idx_th(link_id, ts), INDEX idx_tr_ts(ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS app_settings(k VARCHAR(64) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notify_queue(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, device_id BIGINT, status VARCHAR(16), text TEXT,
  created BIGINT, attempts INT DEFAULT 0, next_try BIGINT, INDEX idx_nq_next(next_try)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_fail(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, ip VARCHAR(64), ts BIGINT, INDEX idx_lf_ip_ts(ip, ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
