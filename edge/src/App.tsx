import { Routes, Route, Navigate } from "react-router-dom";
import Login from "./routes/auth/Login";
import Register from "./routes/auth/Register";
import Otp from "./routes/auth/Otp";
import Forgot from "./routes/auth/Forgot";
import Reset from "./routes/auth/Reset";
import RoleGate from "./components/RoleGate";
import SysadminPortal from "./routes/sysadmin/SysadminPortal";
import Roles from "./routes/sysadmin/Roles";
import Logs from "./routes/sysadmin/Logs";

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/signin" replace />} />
      <Route path="/signin" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route path="/otp" element={<Otp />} />
      <Route path="/forgot" element={<Forgot />} />
      <Route path="/reset" element={<Reset />} />

      <Route path="/workspace/sysadmin" element={<RoleGate requiredRole="sysadmin" />}>
        <Route index element={<SysadminPortal />} />
        <Route path="roles" element={<Roles />} />
        <Route path="logs" element={<Logs />} />
      </Route>
    </Routes>
  );
}
