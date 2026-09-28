import { Link } from "react-router-dom";

export default function SysadminPortal() {
  return (
    <main style={{ maxWidth: 640, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>Sysadmin</h1>
      <ul>
        <li><Link to="/workspace/sysadmin/roles">User roles</Link></li>
        <li><Link to="/workspace/sysadmin/logs">Audit log</Link></li>
      </ul>
    </main>
  );
}
