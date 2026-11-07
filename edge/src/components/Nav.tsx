// The portal bar: a depth-3 glass island 12px from the viewport edges, with
// Watermelon's Fluid Tabs as the section switcher. Same links and the same
// sign-out call as before.
import { useNavigate } from "react-router-dom";
import { api } from "../lib/api";
import { FluidTabs } from "./watermelon/fluid-tabs";
import { Button } from "./watermelon/button";
import { BrandMark } from "./spatial/Brand";
import { ThemeToggle } from "./spatial/ThemeToggle";
import { IconHome, IconLedger, IconSignOut, IconUsers } from "./spatial/icons";

export default function Nav({ name }: { name: string }) {
  const nav = useNavigate();

  async function logout() {
    await api("/auth/logout", {});
    nav("/signin");
  }

  return (
    <header className="sticky top-3 z-50 px-3">
      <div className="sp-glass sp-d3 mx-auto flex h-16 max-w-[1180px] items-center gap-3 rounded-full py-2 pr-2 pl-4 max-md:rounded-[20px]">
        <span className="flex items-center gap-2.5">
          <BrandMark />
          <span className="text-[15px] font-semibold tracking-[-0.01em] max-md:sr-only">Hastra</span>
        </span>
        <div className="mx-auto">
          <FluidTabs
            label="Sysadmin sections"
            tabs={[
              { to: "/workspace/sysadmin", label: "Overview", icon: <IconHome />, end: true },
              { to: "/workspace/sysadmin/roles", label: "Roles", icon: <IconUsers /> },
              { to: "/workspace/sysadmin/logs", label: "Audit log", icon: <IconLedger /> },
            ]}
          />
        </div>
        <span className="text-[13px] text-sp-text-2 max-lg:hidden">
          Signed in as <span className="font-semibold text-foreground">{name}</span>
        </span>
        <ThemeToggle />
        <Button variant="secondary" size="sm" onClick={logout} aria-label="Sign out" className="max-sm:size-10 max-sm:px-0">
          <IconSignOut />
          <span className="max-sm:sr-only">Sign out</span>
        </Button>
      </div>
    </header>
  );
}
