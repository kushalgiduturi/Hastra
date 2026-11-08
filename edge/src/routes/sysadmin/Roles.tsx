import { useEffect, useState } from "react";
import { apiGet, api } from "../../lib/api";
import { Panel } from "../../components/spatial/Panel";
import { Message } from "../../components/spatial/Message";
import { Badge } from "../../components/watermelon/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../../components/watermelon/table";

interface UserRow {
  id: string;
  name: string;
  email: string;
  role: string;
  status: string;
}

const ROLES = ["sysadmin", "admin", "employee", "client", "pending_employee"];

// users.status: pending_verification | active | disabled (0001_pilot_schema.sql)
const statusTone = (s: string) => (s === "active" ? "ok" : s === "pending_verification" ? "warn" : s === "disabled" ? "destructive" : "secondary");

export default function Roles() {
  const [users, setUsers] = useState<UserRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    try {
      const { users } = await apiGet<{ users: UserRow[] }>("/sysadmin/roles");
      setUsers(users);
    } catch (err) {
      setError((err as Error).message);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function changeRole(userId: string, role: string) {
    await api("/sysadmin/roles", { userId, role });
    load();
  }

  return (
    <Panel title="User roles" lead="Every role change is written to the audit log.">
      {error && (
        <div className="mb-4">
          <Message tone="error">{error}</Message>
        </div>
      )}
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Name</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Role</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {users.map((u) => (
            <TableRow key={u.id}>
              <TableCell className="font-medium text-foreground">{u.name}</TableCell>
              <TableCell className="text-sp-text-2">{u.email}</TableCell>
              <TableCell>
                <Badge variant={statusTone(u.status)}>{u.status}</Badge>
              </TableCell>
              <TableCell>
                <select
                  aria-label={`Role for ${u.name}`}
                  value={u.role}
                  onChange={(e) => changeRole(u.id, e.target.value)}
                  className="sp-inset h-9 rounded-full border-0 pr-8 pl-3 text-[13px] font-medium text-foreground"
                >
                  {ROLES.map((r) => (
                    <option key={r} value={r}>
                      {r}
                    </option>
                  ))}
                </select>
              </TableCell>
            </TableRow>
          ))}
          {users.length === 0 && !error && (
            <TableRow className="hover:bg-transparent">
              <TableCell colSpan={4} className="py-10 text-center text-sp-text-2">
                No accounts yet.
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </Panel>
  );
}
