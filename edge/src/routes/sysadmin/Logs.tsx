import { useEffect, useState } from "react";
import { apiGet } from "../../lib/api";

interface LogRow {
  chain_index: number;
  username: string | null;
  action: string;
  ip_address: string | null;
  timestamp: string;
  severity: string;
  incident_type: string | null;
}

export default function Logs() {
  const [rows, setRows] = useState<LogRow[]>([]);
  const [verifyResult, setVerifyResult] = useState<{ ok: boolean; problems: string[]; rowCount: number } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiGet<{ rows: LogRow[] }>("/sysadmin/logs").then((r) => setRows(r.rows)).catch((err) => setError(err.message));
  }, []);

  async function verify() {
    setVerifyResult(null);
    try {
      const result = await apiGet<{ ok: boolean; problems: string[]; rowCount: number }>("/sysadmin/logs?verify=1");
      setVerifyResult(result);
    } catch (err) {
      setError((err as Error).message);
    }
  }

  return (
    <main style={{ maxWidth: 900, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>Audit log</h1>
      <button onClick={verify}>Verify chain integrity</button>
      {verifyResult && (
        <p style={{ color: verifyResult.ok ? "seagreen" : "crimson" }}>
          {verifyResult.ok ? `Chain intact (${verifyResult.rowCount} rows).` : verifyResult.problems.join("; ")}
        </p>
      )}
      {error && <p style={{ color: "crimson" }}>{error}</p>}
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        <thead>
          <tr><th>#</th><th>Who</th><th>Action</th><th>IP</th><th>When</th><th>Severity</th></tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.chain_index}>
              <td>{r.chain_index}</td>
              <td>{r.username ?? "—"}</td>
              <td>{r.action}</td>
              <td>{r.ip_address ?? "—"}</td>
              <td>{new Date(r.timestamp).toLocaleString()}</td>
              <td>{r.severity}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </main>
  );
}
