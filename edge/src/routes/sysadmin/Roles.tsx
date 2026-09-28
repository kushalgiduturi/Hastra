import { useEffect, useState } from "react";
import { apiGet, api } from "../../lib/api";

interface UserRow {
  id: string;
  name: string;
  email: string;
  role: string;
  status: string;
}

const ROLES = ["sysadmin", "admin", "employee", "client", "pending_employee"];

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
    <main style={{ maxWidth: 800, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>User roles</h1>
      {error && <p style={{ color: "crimson" }}>{error}</p>}
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        <thead>
          <tr><th>Name</th><th>Email</th><th>Status</th><th>Role</th></tr>
        </thead>
        <tbody>
          {users.map((u) => (
            <tr key={u.id}>
              <td>{u.name}</td>
              <td>{u.email}</td>
              <td>{u.status}</td>
              <td>
                <select value={u.role} onChange={(e) => changeRole(u.id, e.target.value)}>
                  {ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
                </select>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </main>
  );
}
