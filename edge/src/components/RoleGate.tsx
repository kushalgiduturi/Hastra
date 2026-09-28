import { useEffect, useState } from "react";
import { Navigate, Outlet } from "react-router-dom";
import { apiGet } from "../lib/api";
import Nav from "./Nav";

interface Me {
  userId: string;
  name: string;
  role: string;
}

/**
 * Layout route: confirms the session belongs to `requiredRole` before
 * rendering the nested portal routes, redirecting to /signin otherwise.
 * Mirrors the role check every portals/sysadmin/*.php page does server-side
 * — this is the client-side complement, not a replacement for it; every API
 * route still enforces its own role check independently.
 */
export default function RoleGate({ requiredRole }: { requiredRole: string }) {
  const [me, setMe] = useState<Me | null>(null);
  const [checked, setChecked] = useState(false);

  useEffect(() => {
    apiGet<Me>("/auth/me")
      .then(setMe)
      .catch(() => setMe(null))
      .finally(() => setChecked(true));
  }, []);

  if (!checked) return null;
  if (!me || me.role !== requiredRole) return <Navigate to="/signin" replace />;

  return (
    <>
      <Nav name={me.name} />
      <Outlet />
    </>
  );
}
