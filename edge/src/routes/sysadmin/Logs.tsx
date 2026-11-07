import { useEffect, useState } from "react";
import { apiGet } from "../../lib/api";
import { Panel } from "../../components/spatial/Panel";
import { Message } from "../../components/spatial/Message";
import { Badge } from "../../components/watermelon/badge";
import { Button } from "../../components/watermelon/button";
import { IconShieldCheck } from "../../components/spatial/icons";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/watermelon/table";

interface LogRow {
  chain_index: number;
  username: string | null;
  action: string;
  ip_address: string | null;
  timestamp: string;
  severity: string;
  incident_type: string | null;
}

// audit severity: info | warning | critical (functions/_lib/audit-chain.ts)
const severityTone = (s: string) => (s === "critical" ? "destructive" : s === "warning" ? "warn" : s === "info" ? "info" : "secondary");

// an empty cell reads as "not recorded", not as a missing value
const none = <span className="text-muted-foreground">Not recorded</span>;

export default function Logs() {
  const [rows, setRows] = useState<LogRow[]>([]);
  const [verifyResult, setVerifyResult] = useState<{ ok: boolean; problems: string[]; rowCount: number } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(false);

  useEffect(() => {
    apiGet<{ rows: LogRow[] }>("/sysadmin/logs").then((r) => setRows(r.rows)).catch((err) => setError(err.message));
  }, []);

  async function verify() {
    setVerifyResult(null);
    setVerifying(true);
    try {
      const result = await apiGet<{ ok: boolean; problems: string[]; rowCount: number }>("/sysadmin/logs?verify=1");
      setVerifyResult(result);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setVerifying(false);
    }
  }

  return (
    <Panel
      title="Audit log"
      lead="Every entry is chained to the one before it. Verifying recomputes the chain and reports any entry that was edited, deleted or reordered."
      actions={
        <Button onClick={verify} loading={verifying} loadingLabel="Verifying">
          <IconShieldCheck />
          Verify chain integrity
        </Button>
      }
    >
      {(verifyResult || error) && (
        <div className="mb-5 space-y-2">
          {verifyResult && (
            <Message tone={verifyResult.ok ? "ok" : "error"}>
              {verifyResult.ok ? `Chain intact (${verifyResult.rowCount} rows).` : verifyResult.problems.join("; ")}
            </Message>
          )}
          {error && <Message tone="error">{error}</Message>}
        </div>
      )}
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead className="text-right">#</TableHead>
            <TableHead>Who</TableHead>
            <TableHead>Action</TableHead>
            <TableHead>IP</TableHead>
            <TableHead>When</TableHead>
            <TableHead>Severity</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((r) => (
            <TableRow key={r.chain_index}>
              <TableCell className="text-right font-mono text-sp-text-2">{r.chain_index}</TableCell>
              <TableCell className="font-medium text-foreground">{r.username ?? none}</TableCell>
              <TableCell className="text-foreground">{r.action}</TableCell>
              <TableCell className="font-mono text-sp-text-2">{r.ip_address ?? none}</TableCell>
              <TableCell className="text-sp-text-2">{new Date(r.timestamp).toLocaleString()}</TableCell>
              <TableCell>
                <Badge variant={severityTone(r.severity)}>{r.severity}</Badge>
              </TableCell>
            </TableRow>
          ))}
          {rows.length === 0 && !error && (
            <TableRow className="hover:bg-transparent">
              <TableCell colSpan={6} className="py-10 text-center text-sp-text-2">
                No entries yet.
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </Panel>
  );
}
