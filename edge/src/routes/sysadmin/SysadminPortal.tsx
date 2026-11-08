import { Link } from "react-router-dom";
import { Panel } from "../../components/spatial/Panel";
import { IconArrowRight, IconLedger, IconUsers } from "../../components/spatial/icons";

// Two destinations, so two quick links in the panel (flat rows, no cards).
const LINKS = [
  { to: "/workspace/sysadmin/roles", title: "User roles", body: "See every account and change its role.", icon: <IconUsers /> },
  { to: "/workspace/sysadmin/logs", title: "Audit log", body: "Read the tamper-evident log and verify its chain.", icon: <IconLedger /> },
];

export default function SysadminPortal() {
  return (
    <Panel title="System administration" lead="Manage who can do what, and check that the record of it has not been altered.">
      <ul className="divide-y divide-border border-y border-border">
        {LINKS.map((l) => (
          <li key={l.to}>
            <Link
              to={l.to}
              className="group -mx-3 flex items-center gap-4 rounded-[var(--radius-card)] px-3 py-4 transition-colors hover:bg-muted"
            >
              <span className="sp-inset grid size-10 shrink-0 place-items-center rounded-[10px] text-sp-accent-text [&_svg]:size-5">{l.icon}</span>
              <span className="min-w-0 flex-1">
                <span className="block text-[15px] font-semibold text-foreground">{l.title}</span>
                <span className="block text-[13px] text-sp-text-2">{l.body}</span>
              </span>
              <IconArrowRight className="size-5 text-muted-foreground transition-transform duration-200 group-hover:translate-x-0.5 group-hover:text-foreground" />
            </Link>
          </li>
        ))}
      </ul>
    </Panel>
  );
}
