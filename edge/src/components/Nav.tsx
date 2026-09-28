import { NavLink, useNavigate } from "react-router-dom";
import { api } from "../lib/api";

const linkStyle = ({ isActive }: { isActive: boolean }) => ({
  marginRight: "1.5rem",
  fontWeight: isActive ? 700 : 400,
  textDecoration: isActive ? "underline" : "none",
});

export default function Nav({ name }: { name: string }) {
  const nav = useNavigate();

  async function logout() {
    await api("/auth/logout", {});
    nav("/signin");
  }

  return (
    <nav
      style={{
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        maxWidth: 900,
        margin: "0 auto",
        padding: "1rem",
        borderBottom: "1px solid #ddd",
        fontFamily: "sans-serif",
      }}
    >
      <div>
        <NavLink to="/workspace/sysadmin" end style={linkStyle}>Sysadmin</NavLink>
        <NavLink to="/workspace/sysadmin/roles" style={linkStyle}>Roles</NavLink>
        <NavLink to="/workspace/sysadmin/logs" style={linkStyle}>Audit log</NavLink>
      </div>
      <div>
        <span style={{ marginRight: "1rem", color: "#666" }}>{name}</span>
        <button onClick={logout}>Sign out</button>
      </div>
    </nav>
  );
}
